<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\InventoryItem;
use App\Models\Shift;
use App\Models\Warehouse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

class ReportController extends Controller
{
    public function sales(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->format('Y-m-d'));

        $orders = Order::where('restaurant_id', $restaurantId)
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);

        return response()->json([
            'total_sales' => (float) $orders->sum('total_amount'),
            'total_orders' => (int) $orders->count(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function profitLoss(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->format('Y-m-d'));
        $toEnd = Carbon::parse($to)->endOfDay()->format('Y-m-d H:i:s');

        $revenue = Order::where('restaurant_id', $restaurantId)
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$from, $toEnd])
            ->sum('total_amount');

        $expense = Expense::where('restaurant_id', $restaurantId)
            ->whereBetween('expense_date', [$from, $to])
            ->sum('amount');

        // Wages are recognised when the shift is closed and are treated as an
        // operating expense, exactly like expenses recorded from the finance
        // page, so they reduce the net profit of the period.
        $wages = Shift::wagesSummaryBetween($restaurantId, $from, $to)['total'];
        $expense = (float) $expense + $wages;

        $purchase = Purchase::where('restaurant_id', $restaurantId)
            ->whereBetween('order_date', [$from, $to])
            ->sum('total_amount');

        return response()->json([
            'revenue' => (float) $revenue,
            'expense' => (float) $expense,
            'wages' => (float) $wages,
            'purchase' => (float) $purchase,
            'profit' => (float) ($revenue - $expense - $purchase),
        ]);
    }

    public function topProducts(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->format('Y-m-d'));

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.restaurant_id', $restaurantId)
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->select(
                'order_items.product_id',
                DB::raw('SUM(order_items.quantity) as sales_count'),
                DB::raw('SUM(order_items.total_price) as revenue')
            )
            ->groupBy('order_items.product_id')
            ->orderByDesc('sales_count')
            ->limit(20)
            ->get();

        $products = $rows->map(function ($row) {
            $product = Product::find($row->product_id);
            return $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'sales_count' => (int) $row->sales_count,
                'revenue' => (float) $row->revenue,
                'sale_price' => (float) $product->sale_price,
            ] : null;
        })->filter()->values();

        return response()->json(['products' => $products, 'from' => $from, 'to' => $to]);
    }

    public function inventory(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $warehouseId = $request->query('warehouse_id');

        $products = Product::where('restaurant_id', $restaurantId)
            ->with(['batches' => function ($query) use ($warehouseId) {
                if ($warehouseId) {
                    $query->where('warehouse_id', $warehouseId);
                }
            }, 'category'])
            ->get()
            ->map(function (Product $product) {
                $stock = (float) $product->batches->sum('quantity');
                $minStock = (int) ($product->low_stock_threshold ?: $product->min_stock);
                $nearestExpiry = $product->batches
                    ->filter(fn ($batch) => $batch->expiry_date && (float) $batch->quantity > 0)
                    ->sortBy(fn ($batch) => $batch->expiry_date->timestamp)
                    ->first()?->expiry_date?->toDateString();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'category' => optional($product->category)->name,
                    'sale_unit' => $product->sale_unit,
                    'purchase_unit' => $product->purchase_unit,
                    'stock' => $stock,
                    'min_stock' => $minStock,
                    'low_stock' => $minStock > 0 && $stock <= $minStock,
                    'nearest_expiry' => $nearestExpiry,
                    'stock_value' => (float) ($stock * (float) $product->cost_price),
                ];
            });

        return response()->json(['items' => $products]);
    }

    public function expiredProducts(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $warehouseId = $request->query('warehouse_id');

        $query = ProductBatch::where('restaurant_id', $restaurantId)
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->with(['product:id,name,sku,barcode,sale_unit,purchase_unit', 'warehouse:id,name']);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $rows = $query->orderBy('expiry_date')->get()->map(function (ProductBatch $batch) {
            $quantity = (float) $batch->quantity;
            $cost = (float) $batch->cost_price;

            return [
                'batch_id' => $batch->id,
                'product_id' => $batch->product_id,
                'name' => $batch->product?->name,
                'sku' => $batch->product?->sku,
                'barcode' => $batch->product?->barcode,
                'warehouse' => $batch->warehouse?->name,
                'batch_number' => $batch->batch_number,
                'quantity' => $quantity,
                'cost_price' => $cost,
                'loss' => round($quantity * $cost, 2),
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'days_overdue' => $batch->expiry_date
                    ? (int) floor((now()->startOfDay()->timestamp - $batch->expiry_date->startOfDay()->timestamp) / 86400)
                    : null,
            ];
        });

        return response()->json([
            'expired' => $rows->values(),
            'total_loss' => round((float) $rows->sum('loss'), 2),
            'total_quantity' => (float) $rows->sum('quantity'),
        ]);
    }

    public function warehouse(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $warehouseId = $request->query('warehouse_id');
        abort_unless($warehouseId, 422, 'warehouse_id is required');

        $warehouse = Warehouse::where('restaurant_id', $restaurantId)->findOrFail($warehouseId);

        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : Carbon::now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : Carbon::now()->endOfDay();

        $batches = ProductBatch::where('restaurant_id', $restaurantId)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity', '>', 0)
            ->with(['product:id,name,sku,barcode,sale_unit,purchase_unit,main_unit,sub_unit,conversion_factor', 'warehouse:id,name'])
            ->orderBy('expiry_date')
            ->get();

        $now = Carbon::now();
        $cutoff = $now->copy()->addDays(30);

        $batchRows = $batches->map(function (ProductBatch $batch) {
            return [
                'batch_id' => $batch->id,
                'product_id' => $batch->product_id,
                'name' => $batch->product?->name,
                'sku' => $batch->product?->sku,
                'barcode' => $batch->product?->barcode,
                'batch_number' => $batch->batch_number,
                'quantity' => (float) $batch->quantity,
                'cost_price' => (float) $batch->cost_price,
                'sale_price' => (float) ($batch->sale_price ?? $batch->product?->sale_price ?? 0),
                'min_stock' => (int) ($batch->min_stock ?? $batch->product?->min_stock ?? 0),
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'stock_value' => round((float) $batch->quantity * (float) $batch->cost_price, 2),
            ];
        })->values();

        $expired = $batches->filter(fn ($b) => $b->expiry_date && $b->expiry_date->lt($now));
        $nearExpiry = $batches->filter(fn ($b) => $b->expiry_date && $b->expiry_date->gte($now) && $b->expiry_date->lte($cutoff));

        // Low stock: aggregate quantity per product
        $productStocks = $batches->groupBy('product_id')->map(function ($group) {
            return [
                'product_id' => $group->first()->product_id,
                'name' => $group->first()->product?->name,
                'quantity' => (float) $group->sum('quantity'),
                'min_stock' => (int) ($group->first()->min_stock ?? $group->first()->product?->min_stock ?? 0),
            ];
        })->filter(fn ($row) => $row['min_stock'] > 0 && $row['quantity'] <= $row['min_stock'])->values();

        $totalQuantity = (float) $batches->sum('quantity');
        $stockValue = round((float) $batches->sum(fn ($b) => (float) $b->quantity * (float) $b->cost_price), 2);

        return response()->json([
            'warehouse' => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'type' => $warehouse->type,
                'address' => $warehouse->address,
                'phone' => $warehouse->phone,
            ],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => [
                'products_count' => $batches->pluck('product_id')->unique()->count(),
                'total_quantity' => $totalQuantity,
                'stock_value' => $stockValue,
                'expired_count' => $expired->count(),
                'near_expiry_count' => $nearExpiry->count(),
                'low_stock_count' => $productStocks->count(),
            ],
            'batches' => $batchRows,
            'expired' => $expired->map(fn ($b) => [
                'name' => $b->product?->name,
                'batch_number' => $b->batch_number,
                'quantity' => (float) $b->quantity,
                'expiry_date' => $b->expiry_date?->toDateString(),
                'loss' => round((float) $b->quantity * (float) $b->cost_price, 2),
            ])->values(),
            'near_expiry' => $nearExpiry->map(fn ($b) => [
                'name' => $b->product?->name,
                'batch_number' => $b->batch_number,
                'quantity' => (float) $b->quantity,
                'expiry_date' => $b->expiry_date?->toDateString(),
            ])->values(),
            'low_stock' => $productStocks,
        ]);
    }

    public function purchases(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->format('Y-m-d'));
        $total = Purchase::where('restaurant_id', $restaurantId)
            ->whereBetween('order_date', [$from, $to])
            ->sum('total_amount');
        return response()->json(['total_purchases' => (float) $total, 'from' => $from, 'to' => $to]);
    }

    public function expenses(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->format('Y-m-d'));
        $total = Expense::where('restaurant_id', $restaurantId)
            ->whereBetween('expense_date', [$from, $to])
            ->sum('amount');
        return response()->json(['total_expenses' => (float) $total, 'from' => $from, 'to' => $to]);
    }

    public function staff(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $staff = User::where('restaurant_id', $restaurantId)->where('is_active', true)->get()->map(function ($u) use ($restaurantId) {
            $orders = Order::where('restaurant_id', $restaurantId)
                ->where('cashier_id', $u->id)
                ->where('status', '!=', 'cancelled');
            return [
                'name' => $u->name,
                'username' => $u->username,
                'role' => $u->getRoleNames()->first(),
                'orders' => (int) $orders->count(),
                'revenue' => (float) (clone $orders)->where('payment_status', 'paid')->sum('total_amount'),
            ];
        })->sortByDesc('revenue')->values();
        return response()->json(['staff' => $staff, 'from' => $request->query('from'), 'to' => $request->query('to')]);
    }

    public function paymentMethods(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->format('Y-m-d'));
        $toEnd = Carbon::parse($to)->endOfDay()->format('Y-m-d H:i:s');

        $data = Order::where('restaurant_id', $restaurantId)
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$from, $toEnd])
            ->select('payment_method', DB::raw('SUM(total_amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('payment_method')
            ->get();
        return response()->json(['methods' => $data]);
    }

    public function tax(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->format('Y-m-d'));
        $tax = Order::where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$from, $to])
            ->sum('tax_amount');
        return response()->json(['total_tax' => (float) $tax]);
    }

    public function shifts(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = \App\Models\Shift::where('restaurant_id', $restaurantId)
            ->with('user')
            ->orderByDesc('shift_date')
            ->orderByDesc('id');
        if (! $request->user()->hasPermissionTo('view_all_shifts', 'sanctum')) {
            $query->where('user_id', $request->user()->id);
        }
        if ($request->filled('from')) {
            $query->whereDate('shift_date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('shift_date', '<=', $request->query('to'));
        }

        $shifts = $query->get();
        $orders = Order::where('restaurant_id', $restaurantId)
            ->whereIn('shift_id', $shifts->pluck('id'))
            ->where(function ($orders) {
                $orders->where('payment_status', 'paid')->orWhere('status', 'completed');
            })
            ->get(['id', 'shift_id', 'payment_method', 'total_amount']);
        $paymentsByOrder = Payment::where('paymentable_type', Order::class)
            ->whereIn('paymentable_id', $orders->pluck('id'))
            ->get(['paymentable_id', 'method', 'amount'])
            ->groupBy('paymentable_id');
        $ordersByShift = $orders->groupBy('shift_id');

        $rows = $shifts->map(function ($shift) use ($ordersByShift, $paymentsByOrder) {
            $shiftOrders = $ordersByShift->get($shift->id, collect());
            $byPayment = [];
            foreach ($shiftOrders as $order) {
                $payments = $paymentsByOrder->get($order->id, collect());
                if ($payments->isNotEmpty()) {
                    foreach ($payments as $payment) {
                        $method = $payment->method ?: 'cash';
                        $byPayment[$method] = ($byPayment[$method] ?? 0) + (float) $payment->amount;
                    }
                } else {
                    $method = $order->payment_method ?: 'cash';
                    $byPayment[$method] = ($byPayment[$method] ?? 0) + (float) $order->total_amount;
                }
            }

            return array_merge($shift->toArray(), [
                'shift_number' => $shift->id,
                'name' => 'وردية #'.$shift->id.' - '.\Illuminate\Support\Carbon::parse($shift->shift_date)->format('Y-m-d'),
                'user_name' => $shift->user?->name,
                'orders_count' => $shiftOrders->count(),
                'by_payment_method' => array_map(fn ($amount) => round($amount, 4), $byPayment),
                'total' => round((float) array_sum($byPayment), 4),
            ]);
        });

        return response()->json(['shifts' => $rows]);
    }

    public function export(Request $request, $type)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->format('Y-m-d'));

        if ($type === 'sales') {
            $orders = Order::where('restaurant_id', $restaurantId)
                ->where('payment_status', 'paid')
                ->whereBetween('created_at', [$from, $to])
                ->with('cashier')
                ->get()
                ->map(function ($o) {
                    return [
                        'order_number' => $o->order_number,
                        'date' => $o->created_at,
                        'cashier' => optional($o->cashier)->name,
                        'total' => (float) $o->total_amount,
                        'method' => $o->payment_method,
                        'status' => $o->status,
                    ];
                });

            if ($request->query('format') === 'pdf') {
                $pdf = Pdf::loadView('report.sales', ['orders' => $orders, 'from' => $from, 'to' => $to]);
                return $pdf->download('sales-report.pdf');
            }

            return Excel::download(new \App\Exports\SalesExport($orders), 'sales-report.xlsx');
        }

        return response()->json(['message' => 'نوع التصدير غير مدعوم'], 400);
    }
}
