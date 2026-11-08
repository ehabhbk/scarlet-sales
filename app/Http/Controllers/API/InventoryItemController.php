<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Http\Services\StockService;
use App\Services\JournalPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryItemController extends Controller
{
    public function __construct(protected StockService $stock)
    {
    }
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = InventoryItem::where('restaurant_id', $restaurantId)
            ->with('unit', 'supplier');

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }
        if ($request->has('low_stock')) {
            $query->whereColumn('current_stock', '<=', 'min_stock');
        }
        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        return response()->json($query->orderBy('name')->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'unit_id' => 'required|exists:inventory_units,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:100|unique:inventory_items,sku',
            'category' => 'nullable|string|max:100',
            'current_stock' => 'nullable|numeric|min:0',
            'min_stock' => 'nullable|numeric|min:0',
            'reorder_qty' => 'nullable|numeric|min:0',
            'cost_per_unit' => 'nullable|numeric|min:0',
            'expiry_date' => 'nullable|date',
            'batch_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['current_stock'] = $validated['current_stock'] ?? 0;
        $validated['min_stock'] = $validated['min_stock'] ?? 0;
        $validated['cost_per_unit'] = $validated['cost_per_unit'] ?? 0;
        $validated['is_active'] = $request->boolean('is_active', true);

        $item = InventoryItem::create($validated);

        // Initial stock movement if stock > 0
        if ($validated['current_stock'] > 0) {
            StockMovement::create([
                'restaurant_id' => $validated['restaurant_id'],
                'inventory_item_id' => $item->id,
                'type' => 'in',
                'quantity' => $validated['current_stock'],
                'cost_per_unit' => $validated['cost_per_unit'],
                'total_cost' => $validated['current_stock'] * $validated['cost_per_unit'],
                'reference_type' => 'initial',
                'user_id' => $request->user()->id,
                'notes' => 'رصيد أولي',
            ]);
        }

        return response()->json(['item' => $item->load('unit', 'supplier'), 'message' => 'تم إضافة الصنف'], 201);
    }

    public function show(InventoryItem $inventoryItem)
    {
        if ($inventoryItem->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        return response()->json(['item' => $inventoryItem->load('unit', 'supplier')]);
    }

    public function update(Request $request, InventoryItem $inventoryItem)
    {
        if ($inventoryItem->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'current_stock' => 'sometimes|numeric|min:0',
            'min_stock' => 'sometimes|numeric|min:0',
            'cost_per_unit' => 'sometimes|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ]);
        $inventoryItem->update($validated);

        return response()->json(['item' => $inventoryItem->fresh(), 'message' => 'تم تحديث الصنف']);
    }

    public function destroy(InventoryItem $inventoryItem)
    {
        if ($inventoryItem->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $inventoryItem->delete();

        return response()->json(['message' => 'تم حذف الصنف']);
    }

    public function lowStock(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $items = InventoryItem::where('restaurant_id', $restaurantId)
            ->whereColumn('current_stock', '<=', 'min_stock')
            ->with('unit')
            ->get();

        return response()->json(['low_stock' => $items]);
    }
}

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $suppliers = Supplier::where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['suppliers' => $suppliers]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'tax_number' => 'nullable|string|max:100',
            'balance' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['balance'] = $validated['balance'] ?? 0;
        $validated['is_active'] = $request->boolean('is_active', true);
        $supplier = Supplier::create($validated);

        return response()->json(['supplier' => $supplier, 'message' => 'تم إضافة المورد'], 201);
    }

    public function show(Supplier $supplier)
    {
        if ($supplier->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        return response()->json(['supplier' => $supplier]);
    }

    public function update(Request $request, Supplier $supplier)
    {
        if ($supplier->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'balance' => 'sometimes|numeric',
            'is_active' => 'sometimes|boolean',
        ]);
        $supplier->update($validated);

        return response()->json(['supplier' => $supplier->fresh(), 'message' => 'تم تحديث المورد']);
    }

    public function destroy(Supplier $supplier)
    {
        if ($supplier->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $supplier->delete();

        return response()->json(['message' => 'تم حذف المورد']);
    }
}

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $purchases = Purchase::where('restaurant_id', $restaurantId)
            ->with('supplier', 'user', 'account')
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($purchases);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where('restaurant_id', $request->user()->restaurant_id),
            ],
            'account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $request->user()->restaurant_id)
                    ->whereIn('type', ['asset', 'expense'])
                    ->where('is_active', true)),
            ],
            'purchase_number' => 'required|string|max:100|unique:purchases,purchase_number',
            'status' => 'sometimes|in:draft,ordered,received,cancelled',
            'subtotal' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            'amount_paid' => 'nullable|numeric|min:0',
            'payment_method' => 'required|string|max:50',
            'payment_status' => 'sometimes|in:unpaid,partial,paid',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'nullable|exists:inventory_items,id',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.warehouse_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.batch_number' => 'nullable|string|max:100',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $restaurantId = $request->user()->restaurant_id;
            $supplier = Supplier::find($validated['supplier_id']);
            if ($supplier->restaurant_id !== $restaurantId) {
                abort(403);
            }

            $subtotal = 0;
            foreach ($validated['items'] as $it) {
                $subtotal += (float) $it['quantity'] * (float) $it['unit_cost'];
            }
            $taxAmount = round((float) ($validated['tax_amount'] ?? 0), 2);
            $totalAmount = round((float) ($validated['total_amount'] ?? ($subtotal + $taxAmount)), 2);
            $amountPaid = round((float) ($validated['amount_paid'] ?? 0), 2);
            if ($totalAmount <= 0) {
                throw ValidationException::withMessages([
                    'total_amount' => ['يجب أن يكون إجمالي طلب الشراء أكبر من صفر.'],
                ]);
            }
            if ($amountPaid > $totalAmount) {
                throw ValidationException::withMessages([
                    'amount_paid' => ['المبلغ المدفوع لا يمكن أن يتجاوز إجمالي طلب الشراء.'],
                ]);
            }
            $paymentMethod = $validated['payment_method'];
            app(JournalPostingService::class)
                ->assertConfiguredPaymentMethod($restaurantId, $paymentMethod);
            $purchaseStatus = $validated['status'] ?? 'ordered';

            $purchase = Purchase::create([
                'restaurant_id' => $restaurantId,
                'supplier_id' => $validated['supplier_id'],
                'user_id' => $request->user()->id,
                'account_id' => $validated['account_id'],
                'purchase_number' => $validated['purchase_number'],
                'status' => $purchaseStatus,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'payment_status' => $amountPaid >= $totalAmount ? 'paid' : ($amountPaid > 0 ? 'partial' : 'unpaid'),
                'payment_method' => $paymentMethod,
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $it) {
                $inventoryItemId = $it['inventory_item_id'] ?? null;
                $productId = $it['product_id'] ?? null;
                $warehouseId = $it['warehouse_id'] ?? null;

                if ($inventoryItemId) {
                    $item = InventoryItem::find($inventoryItemId);
                    if ($item->restaurant_id !== $restaurantId) {
                        abort(403);
                    }
                }
                if ($productId) {
                    $product = Product::find($productId);
                    if (!$product || (int) $product->restaurant_id !== $restaurantId) {
                        abort(403);
                    }
                    if (!$warehouseId) {
                        $warehouseId = (int) ($product->default_warehouse_id ?: 0);
                    }
                    if (!$warehouseId) {
                        $warehouseId = Warehouse::where('restaurant_id', $restaurantId)
                            ->where('is_active', true)
                            ->orderBy('is_default', 'desc')
                            ->value('id');
                    }
                }

                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'inventory_item_id' => $inventoryItemId,
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $it['quantity'],
                    'unit_cost' => $it['unit_cost'],
                    'total_cost' => (float) $it['quantity'] * (float) $it['unit_cost'],
                    'expiry_date' => $it['expiry_date'] ?? null,
                    'batch_number' => $it['batch_number'] ?? null,
                ]);
            }

            // If received, add to inventory (product batches or legacy inventory items)
            if ($purchaseStatus === 'received') {
                foreach ($validated['items'] as $it) {
                    $inventoryItemId = $it['inventory_item_id'] ?? null;
                    $productId = $it['product_id'] ?? null;
                    $warehouseId = $it['warehouse_id'] ?? null;
                    $qty = (float) $it['quantity'];
                    $cost = (float) $it['unit_cost'];

                    if ($productId && $warehouseId) {
                        $this->stock->stockIn(
                            $restaurantId,
                            (int) $warehouseId,
                            (int) $productId,
                            $qty,
                            [
                                'cost_price' => $cost,
                                'expiry_date' => $it['expiry_date'] ?? null,
                                'batch_number' => $it['batch_number'] ?? null,
                                'supplier_id' => $validated['supplier_id'],
                            ],
                            (int) $request->user()->id,
                            'purchase_receive'
                        );
                        continue;
                    }
                    if ($inventoryItemId) {
                        $item = InventoryItem::find($inventoryItemId);
                        $item->increment('current_stock', $qty);
                        StockMovement::create([
                            'restaurant_id' => $restaurantId,
                            'inventory_item_id' => $inventoryItemId,
                            'type' => 'in',
                            'quantity' => $qty,
                            'cost_per_unit' => $cost,
                            'total_cost' => $qty * $cost,
                            'reference_type' => 'purchase',
                            'reference_id' => $purchase->id,
                            'user_id' => $request->user()->id,
                            'notes' => "استلام مشتريات #{$validated['purchase_number']}",
                        ]);
                    }
                }
            }

            if ($amountPaid > 0) {
                $payment = Payment::create([
                    'restaurant_id' => $restaurantId,
                    'paymentable_type' => Purchase::class,
                    'paymentable_id' => $purchase->id,
                    'amount' => $amountPaid,
                    'method' => $paymentMethod,
                    'paid_at' => now(),
                    'user_id' => $request->user()->id,
                ]);
                $this->postPurchasePayment($purchase, $payment, $request->user()->id);
            }

            return response()->json([
                'purchase' => $purchase->load('supplier', 'items.product', 'items.inventoryItem', 'items.warehouse', 'account', 'payments'),
                'message' => 'تم إضافة طلب الشراء وقيد المبلغ المدفوع',
            ], 201);
        });
    }

    public function show(Purchase $purchase)
    {
        if ($purchase->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        return response()->json(['purchase' => $purchase->load('supplier', 'user', 'items.product', 'items.inventoryItem', 'items.warehouse', 'account', 'payments')]);
    }

    public function update(Request $request, Purchase $purchase)
    {
        if ($purchase->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'status' => 'sometimes|in:draft,ordered,received,cancelled',
            'notes' => 'sometimes|string',
        ]);
        $purchase->update($validated);

        return response()->json(['purchase' => $purchase->fresh(), 'message' => 'تم تحديث طلب الشراء']);
    }

    public function destroy(Purchase $purchase)
    {
        if ($purchase->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        DB::transaction(function () use ($purchase) {
            JournalEntry::where('restaurant_id', $purchase->restaurant_id)
                ->where('reference', 'like', 'PURCHASE-'.$purchase->id.'-%')
                ->delete();
            Payment::where('restaurant_id', $purchase->restaurant_id)
                ->where('paymentable_type', Purchase::class)
                ->where('paymentable_id', $purchase->id)
                ->delete();
            $purchase->delete();
        });

        return response()->json(['message' => 'تم حذف طلب الشراء']);
    }

    public function recordPayment(Request $request, Purchase $purchase)
    {
        if ($purchase->restaurant_id !== $request->user()->restaurant_id) {
            abort(403);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string|max:50',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $payment = DB::transaction(function () use ($purchase, $validated, $request) {
            $lockedPurchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $amount = round((float) $validated['amount'], 2);
            if ($amount > round((float) $lockedPurchase->total_amount - (float) $lockedPurchase->amount_paid, 2)) {
                throw ValidationException::withMessages([
                    'amount' => ['المبلغ يتجاوز الرصيد المستحق على طلب الشراء.'],
                ]);
            }

            $payment = Payment::create([
                'restaurant_id' => $lockedPurchase->restaurant_id,
                'paymentable_type' => Purchase::class,
                'paymentable_id' => $lockedPurchase->id,
                'amount' => $amount,
                'method' => $validated['method'],
                'reference' => $validated['reference'] ?? null,
                'paid_at' => now(),
                'user_id' => $request->user()->id,
                'notes' => $validated['notes'] ?? null,
            ]);
            $lockedPurchase->amount_paid = round((float) $lockedPurchase->amount_paid + $amount, 4);
            $lockedPurchase->payment_method = $validated['method'];
            $lockedPurchase->payment_status = (float) $lockedPurchase->amount_paid >= (float) $lockedPurchase->total_amount
                ? 'paid'
                : 'partial';
            $lockedPurchase->save();
            $this->postPurchasePayment($lockedPurchase, $payment, $request->user()->id);

            return $payment;
        });

        return response()->json([
            'payment' => $payment,
            'purchase' => $purchase->fresh()->load('account', 'payments'),
            'message' => 'تم تسجيل الدفعة وقيدها محاسبيًا',
        ], 201);
    }

    private function postPurchasePayment(Purchase $purchase, Payment $payment, int $userId): void
    {
        $paymentAccount = app(JournalPostingService::class)
            ->paymentAccount($purchase->restaurant_id, $payment->method);
        app(JournalPostingService::class)->post(
            $purchase->restaurant_id,
            'PUR-',
            'PURCHASE-'.$purchase->id.'-PAYMENT-'.$payment->id,
            $payment->paid_at->toDateString(),
            'دفعة شراء رقم '.$purchase->purchase_number,
            $userId,
            [
                [
                    'account_id' => $purchase->account_id,
                    'debit' => $payment->amount,
                    'description' => 'مشتريات '.$purchase->purchase_number,
                ],
                [
                    'account_id' => $paymentAccount->id,
                    'credit' => $payment->amount,
                    'description' => 'سداد مشتريات '.$purchase->purchase_number,
                ],
            ]
        );
    }
}

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = StockMovement::where('restaurant_id', $restaurantId)
            ->with('inventoryItem', 'user');

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('inventory_item_id')) {
            $query->where('inventory_item_id', $request->inventory_item_id);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'type' => 'required|in:in,out,adjustment',
            'quantity' => 'required|numeric|min:0',
            'cost_per_unit' => 'nullable|numeric|min:0',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'notes' => 'nullable|string',
        ]);

        $item = InventoryItem::find($validated['inventory_item_id']);
        if ($item->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        $totalCost = (float) $validated['quantity'] * ((float) $validated['cost_per_unit'] ?? 0);

        return DB::transaction(function () use ($item, $validated, $totalCost, $request) {
            $movement = StockMovement::create([
                'restaurant_id' => $item->restaurant_id,
                'inventory_item_id' => $validated['inventory_item_id'],
                'type' => $validated['type'],
                'quantity' => (float) $validated['quantity'],
                'cost_per_unit' => (float) ($validated['cost_per_unit'] ?? 0),
                'total_cost' => $totalCost,
                'reference_type' => $validated['reference_type'] ?? null,
                'reference_id' => $validated['reference_id'] ?? null,
                'user_id' => $request->user()->id,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update current stock
            if ($validated['type'] === 'in') {
                $item->increment('current_stock', (float) $validated['quantity']);
            } elseif ($validated['type'] === 'out') {
                $item->decrement('current_stock', (float) $validated['quantity']);
            } else {
                // adjustment: set directly
                $item->update(['current_stock' => (float) $validated['quantity']]);
            }

            return response()->json(['movement' => $movement->load('inventoryItem'), 'message' => 'تم تسجيل حركة المخزون'], 201);
        });
    }

    public function show(StockMovement $stockMovement)
    {
        if ($stockMovement->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        return response()->json(['movement' => $stockMovement->load('inventoryItem', 'user')]);
    }

    public function destroy(StockMovement $stockMovement)
    {
        if ($stockMovement->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $stockMovement->delete();

        return response()->json(['message' => 'تم حذف الحركة']);
    }
}
