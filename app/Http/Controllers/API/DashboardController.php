<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Support\PublicMediaUrl;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Warehouse;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\InventoryItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $now = Carbon::now();

        $todayRevenue = (float) Order::where('restaurant_id', $restaurantId)
            ->where('payment_status', 'paid')
            ->whereDate('created_at', $today)
            ->sum('total_amount');

        $todayPurchases = (float) Purchase::where('restaurant_id', $restaurantId)
            ->where('status', '!=', 'cancelled')
            ->whereDate('order_date', $today)
            ->sum('total_amount');

        $todayExpenses = (float) Expense::where('restaurant_id', $restaurantId)
            ->whereDate('expense_date', $today)
            ->sum('amount');
        $todayWages = Shift::wagesSummaryBetween($restaurantId, $today, $today)['total'];
        $todayExpenses += $todayWages;

        $monthRevenue = (float) Order::where('restaurant_id', $restaurantId)
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$monthStart, $now])
            ->sum('total_amount');

        $monthPurchases = (float) Purchase::where('restaurant_id', $restaurantId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('order_date', [$monthStart->toDateString(), $today->toDateString()])
            ->sum('total_amount');

        $monthExpenses = (float) Expense::where('restaurant_id', $restaurantId)
            ->whereBetween('expense_date', [$monthStart->toDateString(), $today->toDateString()])
            ->sum('amount');
        $monthWages = Shift::wagesSummaryBetween($restaurantId, $monthStart, $today)['total'];
        $monthExpenses += $monthWages;

        $completed = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'completed')->whereDate('created_at', $today)->count();
        $cancelled = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'cancelled')->whereDate('created_at', $today)->count();
        $pending = Order::where('restaurant_id', $restaurantId)
            ->whereIn('status', ['pending', 'confirmed', 'preparing', 'ready'])->count();

        [$from, $to] = $this->resolveRange($request);

        $periodRevenue = (float) Order::where('restaurant_id', $restaurantId)
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$from, $to])
            ->sum('total_amount');

        $periodPurchases = (float) Purchase::where('restaurant_id', $restaurantId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])
            ->sum('total_amount');

        $periodExpenses = (float) Expense::where('restaurant_id', $restaurantId)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');
        $periodWages = Shift::wagesSummaryBetween($restaurantId, $from, $to)['total'];
        $periodExpenses += $periodWages;

        $periodOrders = Order::where('restaurant_id', $restaurantId)
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $monthProfit = $monthRevenue - $monthExpenses - $monthPurchases;

        $lowStock = InventoryItem::where('restaurant_id', $restaurantId)
            ->whereColumn('current_stock', '<=', 'min_stock')->count();

        return response()->json([
            'today_revenue' => $todayRevenue,
            'today_purchases' => $todayPurchases,
            'today_expenses' => $todayExpenses,
            'today_profit' => $todayRevenue - $todayExpenses - $todayPurchases,
            'today_orders' => (int) Order::where('restaurant_id', $restaurantId)->whereDate('created_at', $today)->count(),

            'month_revenue' => $monthRevenue,
            'month_purchases' => $monthPurchases,
            'month_expenses' => $monthExpenses,
            'month_profit' => $monthProfit,
            'month_orders' => (int) Order::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$monthStart, $now])->count(),

            // Backwards-compatible aliases used by older widgets.
            'month_expense' => $monthExpenses,
            'net_profit' => $monthProfit,

            'completed_today' => (int) $completed,
            'cancelled_today' => (int) $cancelled,
            'pending_orders' => (int) $pending,
            'low_stock_alerts' => (int) $lowStock,

            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'revenue' => $periodRevenue,
                'purchases' => $periodPurchases,
                'expenses' => $periodExpenses,
                'wages' => $periodWages,
                'profit' => $periodRevenue - $periodExpenses - $periodPurchases,
                'orders' => (int) $periodOrders,
            ],
        ]);
    }

    public function salesChart(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        if ($request->filled('from') || $request->filled('to')) {
            [$from, $to] = $this->resolveRange($request);
            $days = $from->diffInDays($to) + 1;
            if ($days > 366) {
                $from = $to->copy()->subDays(365)->startOfDay();
            }
        } else {
            $range = $request->query('range', '7d');
            $days = $range === '30d' ? 30 : ($range === '90d' ? 90 : 7);
            $from = Carbon::now()->subDays($days - 1)->startOfDay();
            $to = Carbon::now()->endOfDay();
        }

        $sales = Order::where('restaurant_id', $restaurantId)
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$from, $to])
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(total_amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $labels = [];
        $data = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $date = $cursor->format('Y-m-d');
            $labels[] = $cursor->format('d M');
            $found = $sales->firstWhere('date', $date);
            $data[] = $found ? (float) $found->total : 0;
        }

        return response()->json([
            'labels' => $labels,
            'data' => $data,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    public function topProducts(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $limit = min(max((int) $request->query('limit', 10), 1), 50);

        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.restaurant_id', $restaurantId)
            ->where('orders.status', '!=', 'cancelled');

        if ($request->filled('from') || $request->filled('to')) {
            [$from, $to] = $this->resolveRange($request);
            $query->whereBetween('orders.created_at', [$from, $to]);
        }

        $rows = $query
            ->select(
                'order_items.product_id',
                DB::raw('SUM(order_items.quantity) as sales_count'),
                DB::raw('COUNT(DISTINCT order_items.order_id) as orders_count'),
                DB::raw('SUM(order_items.total_price) as revenue')
            )
            ->groupBy('order_items.product_id')
            ->orderByDesc('sales_count')
            ->limit($limit)
            ->get();

        $products = $rows->map(function ($row) {
            $product = Product::find($row->product_id);
            if (!$product) {
                return null;
            }
            return [
                'id' => $product->id,
                'name' => $product->name,
                'sales_count' => (int) $row->sales_count,
                'orders_count' => (int) $row->orders_count,
                'revenue' => (float) $row->revenue,
                'sale_price' => (float) $product->sale_price,
                'image' => PublicMediaUrl::make($product->image),
            ];
        })->filter()->values();

        return response()->json(['products' => $products]);
    }

    public function staffPerformance(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        [$from, $to] = $this->resolveRange($request);

        $staff = User::where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->with('roles')
            ->get()
            ->map(function ($u) use ($restaurantId, $from, $to) {
                $orders = Order::where('restaurant_id', $restaurantId)
                    ->where('cashier_id', $u->id)
                    ->where('status', '!=', 'cancelled')
                    ->whereBetween('created_at', [$from, $to]);
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'username' => $u->username,
                    'role' => $u->getRoleNames()->first(),
                    'orders_completed' => (int) (clone $orders)->count(),
                    'revenue' => (float) (clone $orders)->where('payment_status', 'paid')->sum('total_amount'),
                ];
            })
            ->filter(fn ($row) => $row['orders_completed'] > 0)
            ->sortByDesc('revenue')
            ->values();

        return response()->json(['staff' => $staff]);
    }

    public function breakdowns(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        [$from, $to] = $this->resolveRange($request);
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $normalize = fn ($rows) => $rows->map(fn ($row) => [
            'name' => $row->name ?: 'غير محدد',
            'value' => (float) $row->value,
            'count' => (int) ($row->count ?? 0),
        ])->filter(fn ($row) => $row['value'] > 0)->values();

        $sales = $normalize(Order::where('restaurant_id', $restaurantId)
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$from, $to])
            ->select('type as name', DB::raw('SUM(total_amount) as value'), DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->orderByDesc('value')
            ->get());

        $purchases = $normalize(DB::table('purchases')
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.restaurant_id', $restaurantId)
            ->where('purchases.status', '!=', 'cancelled')
            ->whereBetween('purchases.order_date', [$fromDate, $toDate])
            ->select('suppliers.name as name', DB::raw('SUM(purchases.total_amount) as value'), DB::raw('COUNT(*) as count'))
            ->groupBy('suppliers.name')
            ->orderByDesc('value')
            ->get());

        $expenses = $normalize(Expense::where('restaurant_id', $restaurantId)
            ->whereBetween('expense_date', [$fromDate, $toDate])
            ->select('category as name', DB::raw('SUM(amount) as value'), DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->orderByDesc('value')
            ->get());

        // Shift wages are part of the period expenses even though they are not
        // rows in the expenses table.
        $wagesSummary = Shift::wagesSummaryBetween($restaurantId, $fromDate, $toDate);
        if ($wagesSummary['total'] > 0) {
            $expenses->push([
                'name' => 'أجور موظفين',
                'value' => $wagesSummary['total'],
                'count' => $wagesSummary['count'],
            ]);
            $expenses = $expenses->sortByDesc('value')->values();
        }

        $productQuery = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.restaurant_id', $restaurantId)
            ->where('orders.payment_status', 'paid')
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$from, $to]);

        $productTotal = (float) (clone $productQuery)->sum('order_items.total_price');
        $productTotalCount = (float) (clone $productQuery)->sum('order_items.quantity');
        $productRows = (clone $productQuery)
            ->select(
                'products.name as name',
                DB::raw('SUM(order_items.total_price) as value'),
                DB::raw('SUM(order_items.quantity) as count')
            )
            ->groupBy('products.name')
            ->orderByDesc('value')
            ->limit(8)
            ->get();

        $products = $normalize($productRows);
        $shownProductTotal = (float) $productRows->sum('value');
        if ($productTotal - $shownProductTotal > 0.01) {
            $products->push([
                'name' => 'منتجات أخرى',
                'value' => $productTotal - $shownProductTotal,
                'count' => (int) max(0, $productTotalCount - (float) $productRows->sum('count')),
            ]);
        }

        $categories = $normalize(DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('orders.restaurant_id', $restaurantId)
            ->where('orders.payment_status', 'paid')
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$from, $to])
            ->select(
                DB::raw("COALESCE(categories.name, 'بدون صنف') as name"),
                DB::raw('SUM(order_items.total_price) as value'),
                DB::raw('SUM(order_items.quantity) as count')
            )
            ->groupByRaw("COALESCE(categories.name, 'بدون صنف')")
            ->orderByDesc('value')
            ->get());

        $employees = $normalize(DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.cashier_id')
            ->where('orders.restaurant_id', $restaurantId)
            ->where('orders.payment_status', 'paid')
            ->whereBetween('orders.created_at', [$from, $to])
            ->select('users.name as name', DB::raw('SUM(orders.total_amount) as value'), DB::raw('COUNT(*) as count'))
            ->groupBy('users.name')
            ->orderByDesc('value')
            ->get());

        $paymentRows = DB::table('payments')
            ->where('restaurant_id', $restaurantId)
            ->where('paymentable_type', Order::class)
            ->whereBetween('paid_at', [$from, $to])
            ->select('method as name', DB::raw('SUM(amount) as value'), DB::raw('COUNT(*) as count'))
            ->groupBy('method')
            ->orderByDesc('value')
            ->get();

        if ($paymentRows->isEmpty()) {
            $paymentRows = Order::where('restaurant_id', $restaurantId)
                ->where('payment_status', 'paid')
                ->whereBetween('created_at', [$from, $to])
                ->whereNotNull('payment_method')
                ->select('payment_method as name', DB::raw('SUM(total_amount) as value'), DB::raw('COUNT(*) as count'))
                ->groupBy('payment_method')
                ->orderByDesc('value')
                ->get();
        }

        return response()->json([
            'from' => $fromDate,
            'to' => $toDate,
            'sales' => $sales,
            'purchases' => $purchases,
            'expenses' => $expenses,
            'products' => $products->values(),
            'categories' => $categories,
            'employees' => $employees,
            'payment_methods' => $normalize($paymentRows),
        ]);
    }

    public function inventoryAlerts(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        $items = InventoryItem::where('restaurant_id', $restaurantId)
            ->whereColumn('current_stock', '<=', 'min_stock')
            ->get()
            ->map(function ($i) {
                return [
                    'id' => $i->id,
                    'name' => $i->name,
                    'current_stock' => (float) $i->current_stock,
                    'min_stock' => (float) $i->min_stock,
                    'unit' => optional($i->unit)->abbreviation,
                    'sku' => $i->sku,
                ];
            });

        return response()->json(['low_stock' => $items]);
    }

    public function warehouseStats(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        $warehousesCount = Warehouse::where('restaurant_id', $restaurantId)
            ->where('type', '!=', 'store')
            ->where('is_active', true)
            ->count();
        $storesCount = Warehouse::where('restaurant_id', $restaurantId)
            ->where('type', 'store')
            ->where('is_active', true)
            ->count();

        $now = Carbon::now();
        $nearExpiryCutoff = $now->copy()->addDays(30);

        $batches = ProductBatch::where('restaurant_id', $restaurantId)
            ->where('quantity', '>', 0)
            ->get();

        $expiredCount = $batches->filter(fn ($b) => $b->expiry_date && $b->expiry_date->lt($now))->count();
        $nearExpiryCount = $batches->filter(fn ($b) => $b->expiry_date
            && $b->expiry_date->gte($now)
            && $b->expiry_date->lte($nearExpiryCutoff))->count();

        // Low stock: products whose total batch quantity <= effective min_stock
        $lowStockCount = Product::where('restaurant_id', $restaurantId)
            ->where('track_inventory', true)
            ->get()
            ->filter(function (Product $product) {
                $totalStock = (float) $product->batches()->sum('quantity');
                $threshold = (int) ($product->batches()->whereNotNull('min_stock')->value('min_stock') ?? $product->min_stock);
                return $totalStock <= $threshold;
            })
            ->count();

        return response()->json([
            'warehouses_count' => $warehousesCount,
            'stores_count' => $storesCount,
            'expired_products' => $expiredCount,
            'near_expiry_products' => $nearExpiryCount,
            'low_stock_products' => $lowStockCount,
        ]);
    }
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(Request $request): array
    {
        $from = Carbon::now()->startOfMonth();
        $to = Carbon::now()->endOfDay();

        if ($request->filled('from')) {
            try {
                $from = Carbon::parse((string) $request->query('from'))->startOfDay();
            } catch (\Throwable) {
                $from = Carbon::now()->startOfMonth();
            }
        }

        if ($request->filled('to')) {
            try {
                $to = Carbon::parse((string) $request->query('to'))->endOfDay();
            } catch (\Throwable) {
                $to = Carbon::now()->endOfDay();
            }
        }

        if ($to->lt($from)) {
            $swap = $from->copy();
            $from = $to->copy()->startOfDay();
            $to = $swap->copy()->endOfDay();
        }

        return [$from, $to];
    }
}
