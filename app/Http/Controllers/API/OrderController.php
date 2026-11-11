<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Services\StockService;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderKitchenStation;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductModifier;
use App\Models\Recipe;
use App\Models\Shift;
use App\Models\ShiftCashierBalance;
use App\Models\StockMovement;
use App\Models\Table;
use App\Services\JournalPostingService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    protected StockService $stock;

    public function __construct(StockService $stock)
    {
        $this->stock = $stock;
    }

    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        abort_unless($request->user()->hasPermissionTo('view_orders', 'sanctum'), 403);
        $query = Order::where('restaurant_id', $restaurantId)
            ->with([
                'table', 'customer', 'cashier', 'waiter', 'payments',
                'items.product', 'items.size', 'items.modifiers.modifier', 'items.modifiers.modifierOption',
            ]);

        if (! $request->user()->hasPermissionTo('view_all_orders', 'sanctum')) {
            $query->where('cashier_id', $request->user()->id);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('table_id')) {
            $query->where('table_id', $request->table_id);
        }
        if ($request->filled('shift_id')) {
            $query->where('shift_id', $request->integer('shift_id'));
        }
        if ($request->has('search')) {
            $query->where('order_number', 'like', "%{$request->search}%");
        }

        $perPage = min(max($request->integer('per_page', 15), 1), 100);
        $orders = $query->orderByDesc('created_at')->paginate($perPage);

        return OrderResource::collection($orders);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermissionTo('create_orders', 'sanctum'), 403);
        $validated = $request->validate([
            'table_id' => 'nullable|exists:tables,id',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'waiter_id' => 'nullable|exists:users,id',
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $request->user()->restaurant_id)
                    ->where('is_active', true)),
            ],
            'type' => 'required|in:dine_in,takeaway,delivery',
            'payment_method' => 'required|string|max:50',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'delivery_fee' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'customer_notes' => 'nullable|string',
            'delivery_address' => 'nullable|string',
            'delivery_phone' => 'nullable|string',
            'amount_paid' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.size_id' => 'nullable|exists:product_sizes,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_type' => 'nullable|in:main,sub',
            'items.*.unit_name' => 'nullable|string|max:50',
            'items.*.conversion_factor' => 'nullable|numeric|min:0.001',
            'items.*.base_quantity' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
            'items.*.modifier_options' => 'nullable|array',
            'items.*.modifier_options.*.modifier_id' => 'required|exists:product_modifiers,id',
            'items.*.modifier_options.*.modifier_option_id' => 'required|exists:modifier_options,id',
        ]);

        $user = $request->user();
        $restaurantId = $user->restaurant_id;
        app(JournalPostingService::class)
            ->assertConfiguredPaymentMethod($restaurantId, $validated['payment_method']);

        return DB::transaction(function () use ($validated, $user, $restaurantId) {
            $warehouseId = $this->stock->resolveWarehouseId(
                isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
                (int) $restaurantId
            );

            // Restrict selling to warehouses assigned to the user (can_sell)
            $sellableWarehouseIds = $user->warehouses()->wherePivot('can_sell', true)->pluck('warehouses.id')->map(fn ($id) => (int) $id)->all();
            if (count($sellableWarehouseIds) > 0 && $warehouseId && ! in_array($warehouseId, $sellableWarehouseIds)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'غير مسموح لك البيع من هذا المخزن/نقطة البيع.',
                    'errors' => ['warehouse_id' => ['غير مسموح لك البيع من هذا الموقع.']],
                ], 403));
            }

            // The shift is shared by all POS users: a single open shift per restaurant.
            $currentShift = Shift::where('restaurant_id', $restaurantId)
                ->where('status', 'open')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $currentShift) {
                $message = 'لا يمكن إنشاء طلب قبل فتح وردية جديدة.';
                throw new HttpResponseException(response()->json([
                    'message' => $message,
                    'errors' => ['shift' => [$message]],
                ], 422));
            }

            // Every cashier keeps their own opening cash/revenue inside the shared shift.
            ShiftCashierBalance::firstOrCreate(
                ['shift_id' => $currentShift->id, 'user_id' => $user->id],
                [
                    'restaurant_id' => $restaurantId,
                    'opening_cash' => 0,
                    'opening_cash_set' => false,
                ]
            );

            $orderNumber = $this->nextOrderNumber();

            $subtotal = 0;
            $taxAmount = 0;

            // Calculate totals from product prices (server-side, never trust client prices)
            foreach ($validated['items'] as $item) {
                $product = Product::where('restaurant_id', $restaurantId)
                    ->findOrFail($item['product_id']);
                $subtotal += $this->calculateItemPrice($product, $item) * $item['quantity'];
            }

            $discountAmount = $validated['discount_amount'] ?? 0;
            $discountPercent = $validated['discount_percent'] ?? 0;
            if ($discountPercent > 0) {
                $discountAmount += ($subtotal * $discountPercent / 100);
            }
            $discountAmount = min($discountAmount, $subtotal);

            $taxRate = $validated['tax_rate'] ?? null;
            if ($taxRate === null) {
                $restaurant = $user->restaurant;
                $taxRate = $restaurant ? (float) $restaurant->tax_rate : 0;
            }
            $taxAmount = ($subtotal - $discountAmount) * $taxRate;
            $deliveryFee = $validated['delivery_fee'] ?? 0;
            $totalAmount = $subtotal - $discountAmount + $taxAmount + $deliveryFee;
            $amountPaid = $validated['amount_paid'] ?? 0;
            $changeDue = max(0, $amountPaid - $totalAmount);

            // Resolve / create the walk-in customer from the name entered at the POS.
            $customerId = $validated['customer_id'] ?? null;
            if (! $customerId && ! empty($validated['customer_name'])) {
                $customerQuery = Customer::where('restaurant_id', $restaurantId);
                if (! empty($validated['customer_phone'])) {
                    $customerQuery->where('phone', $validated['customer_phone']);
                } else {
                    $customerQuery->where('name', $validated['customer_name']);
                }
                $customer = $customerQuery->first();
                if (! $customer) {
                    $customer = Customer::create([
                        'restaurant_id' => $restaurantId,
                        'name' => $validated['customer_name'],
                        'phone' => $validated['customer_phone'] ?? null,
                        'customer_type' => 'regular',
                        'is_active' => true,
                    ]);
                }
                $customerId = $customer->id;
            }

            $order = Order::create([
                'restaurant_id' => $restaurantId,
                'table_id' => $validated['table_id'] ?? null,
                'customer_id' => $customerId,
                'cashier_id' => $user->id,
                'waiter_id' => $validated['waiter_id'] ?? null,
                'shift_id' => $currentShift?->id,
                'warehouse_id' => $warehouseId,
                'order_number' => $orderNumber,
                'type' => $validated['type'],
                'status' => 'pending',
                'payment_status' => $amountPaid >= $totalAmount ? 'paid' : ($amountPaid > 0 ? 'partial' : 'unpaid'),
                'payment_method' => $validated['payment_method'],
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'discount_percent' => $discountPercent,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'change_due' => $changeDue,
                'notes' => $validated['notes'] ?? null,
                'customer_notes' => $validated['customer_notes'] ?? null,
                'delivery_address' => $validated['delivery_address'] ?? null,
                'delivery_phone' => $validated['delivery_phone'] ?? null,
                'delivery_fee' => $deliveryFee,
            ]);

            // Order items + modifiers
            $sort = 0;
            foreach ($validated['items'] as $itemData) {
                $product = Product::where('restaurant_id', $restaurantId)
                    ->findOrFail($itemData['product_id']);

                // Determine unit type and price
                $unitType = $itemData['unit_type'] ?? 'main';
                $conversionFactor = (float) ($itemData['conversion_factor'] ?? $product->conversion_factor ?? 1);
                $subUnitPrice = (float) ($itemData['sub_unit_price'] ?? $product->sub_unit_price ?? 0);

                if ($unitType === 'sub' && $product->sub_unit && $conversionFactor > 0) {
                    // Price per sub-unit: use sub_unit_price if set, otherwise sale_price / conversion_factor
                    $unitPrice = $subUnitPrice > 0 ? $subUnitPrice : (($this->calculateItemPrice($product, $itemData)) / $conversionFactor);
                    // Stock deduction in main units: quantity / conversion_factor
                    $stockDeductionQty = (float) $itemData['quantity'] / $conversionFactor;
                    $baseQuantity = (float) $itemData['quantity']; // stored in sub-units
                } else {
                    $unitPrice = $this->calculateItemPrice($product, $itemData);
                    $stockDeductionQty = (float) $itemData['quantity'];
                    $baseQuantity = (float) $itemData['quantity'] * $conversionFactor; // stored in sub-units
                    $unitType = 'main';
                }

                $itemDiscount = 0;
                $itemTax = 0;
                $itemTotal = ($unitPrice * $itemData['quantity']) - $itemDiscount + $itemTax;

                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $itemData['product_id'],
                    'size_id' => $itemData['size_id'] ?? null,
                    'quantity' => $itemData['quantity'],
                    'unit_type' => $unitType,
                    'unit_name' => $itemData['unit_name'] ?? ($unitType === 'sub' ? $product->sub_unit : $product->sale_unit),
                    'conversion_factor' => $conversionFactor,
                    'base_quantity' => $baseQuantity,
                    'unit_price' => $unitPrice,
                    'discount_amount' => $itemDiscount,
                    'tax_amount' => $itemTax,
                    'total_price' => $itemTotal,
                    'notes' => $itemData['notes'] ?? null,
                    'kitchen_status' => 'pending',
                    'sort_order' => $sort++,
                ]);
                if ($product->kitchen_station_id) {
                    OrderKitchenStation::create([
                        'order_item_id' => $orderItem->id,
                        'station_id' => $product->kitchen_station_id,
                    ]);
                }

                // Modifiers
                if (! empty($itemData['modifier_options'])) {
                    foreach ($itemData['modifier_options'] as $modOpt) {
                        $option = ModifierOption::findOrFail($modOpt['modifier_option_id']);
                        OrderItemModifier::create([
                            'order_item_id' => $orderItem->id,
                            'modifier_id' => $modOpt['modifier_id'],
                            'modifier_option_id' => $modOpt['modifier_option_id'],
                            'price_modifier' => $option->price_modifier,
                        ]);
                    }
                }

                // Deduct warehouse stock (batches, FEFO) using main-unit quantity.
                $this->stock->deductFefo(
                    (int) $restaurantId,
                    $warehouseId,
                    $product,
                    $stockDeductionQty,
                    (int) $user->id,
                    'order',
                    (int) $order->id
                );
                $this->deductInventoryForProduct($product->id, $stockDeductionQty, $user->id, $order->id);

                // Keep the product's sales counter in sync for dashboards/reports (count in sub-units sold).
                $product->increment('sales_count', (int) round($baseQuantity));
            }

            // Update table status
            if (! empty($validated['table_id'])) {
                $table = Table::find($validated['table_id']);
                if ($table && $table->restaurant_id === $restaurantId) {
                    $table->update(['status' => 'occupied']);
                }
            }

            // Update customer stats
            if ($customerId) {
                $customer = Customer::find($customerId);
                if ($customer && $customer->restaurant_id === $restaurantId) {
                    $customer->increment('total_orders');
                    $customer->increment('total_spent', $totalAmount);
                }
            }

            // Status history
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'old_status' => null,
                'new_status' => 'pending',
                'changed_by' => $user->id,
                'notes' => 'طلب جديد',
            ]);

            // Record the collected amount so reports/shift revenue have a payment row.
            if ($amountPaid > 0) {
                Payment::create([
                    'restaurant_id' => $restaurantId,
                    'paymentable_type' => Order::class,
                    'paymentable_id' => $order->id,
                    // Revenue is the order total; any surplus is change handed back.
                    'amount' => min((float) $amountPaid, (float) $totalAmount),
                    'method' => $validated['payment_method'],
                    'reference' => null,
                    'transaction_id' => null,
                    'paid_at' => now(),
                    'user_id' => $user->id,
                    'notes' => 'دفعة عند إنشاء الطلب',
                ]);
            }

            return response()->json([
                'order' => $order->load(['items.product', 'items.modifiers.modifierOption', 'table', 'customer', 'cashier']),
                'message' => 'تم إنشاء الطلب بنجاح',
            ], 201);
        }, 5);
    }

    public function show(Order $order)
    {
        $this->authorizeOrder($order, request(), 'view_orders');
        $order->load(['items.product.sizes', 'items.modifiers.modifier', 'items.modifiers.modifierOption', 'table', 'customer', 'cashier', 'waiter', 'statusHistories.changedBy', 'payments']);

        return new OrderResource($order);
    }

    public function update(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request, 'edit_orders');
        if (in_array($order->status, ['cancelled'], true) || $order->payment_status === 'refunded') {
            return response()->json(['message' => 'لا يمكن تعديل طلب ملغي أو مسترد'], 400);
        }
        if (
            $request->has('payment_methods')
            || $request->has('amount_received')
            || $request->has('payment_method')
        ) {
            abort_unless(
                $request->user()->hasPermissionTo('edit_order_payment', 'sanctum'),
                403,
                'لا تملك صلاحية تعديل طريقة دفع الطلب'
            );
        }
        $validated = $request->validate([
            'notes' => 'nullable|string',
            'customer_notes' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_percent' => 'sometimes|numeric|min:0|max:100',
            'payment_method' => 'sometimes|nullable|string|max:50',
            'payment_methods' => 'sometimes|array',
            'amount_received' => 'sometimes|numeric|min:0',
            'items' => 'sometimes|array|min:1',
            'items.*.product_id' => 'required_with:items|integer|exists:products,id',
            'items.*.size_id' => 'nullable|integer|exists:product_sizes,id',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.unit_type' => 'nullable|in:main,sub',
            'items.*.unit_name' => 'nullable|string|max:50',
            'items.*.conversion_factor' => 'nullable|numeric|min:0.001',
            'items.*.base_quantity' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
            'items.*.modifier_options' => 'nullable|array',
            'items.*.modifier_options.*.modifier_id' => 'required|integer|exists:product_modifiers,id',
            'items.*.modifier_options.*.modifier_option_id' => 'required|integer|exists:modifier_options,id',
        ]);

        return DB::transaction(function () use ($validated, $request, $order) {
            $updates = collect($validated)
                ->except(['items', 'amount_received', 'payment_methods', 'payment_method'])
                ->all();

            $amountReceivedProvided = array_key_exists('amount_received', $validated);
            $amountReceived = $amountReceivedProvided ? (float) $validated['amount_received'] : null;
            $singlePaymentMethod = isset($validated['payment_method'])
                && trim((string) $validated['payment_method']) !== ''
                ? trim((string) $validated['payment_method'])
                : null;

            // Normalise requested payment methods. Every entry may be either a plain
            // method name or an object containing an explicit amount.
            $requestedMethods = [];
            foreach (($validated['payment_methods'] ?? []) as $entry) {
                if (is_array($entry)) {
                    $method = trim((string) ($entry['method'] ?? $entry['value'] ?? ''));
                    if ($method === '') {
                        continue;
                    }
                    $requestedMethods[] = [
                        'method' => $method,
                        'amount' => array_key_exists('amount', $entry) ? (float) $entry['amount'] : null,
                    ];
                } else {
                    $method = trim((string) $entry);
                    if ($method !== '') {
                        $requestedMethods[] = ['method' => $method, 'amount' => null];
                    }
                }
            }

            $total = (float) $order->total_amount;

            if (isset($validated['items'])) {
                $user = $request->user();
                $restaurant = $user->restaurant;
                $oldSubtotal = (float) $order->subtotal;
                $discountPercent = $validated['discount_percent'] ?? (float) $order->discount_percent;
                $fixedDiscount = isset($validated['discount_amount'])
                    ? (float) $validated['discount_amount']
                    : max(0, (float) $order->discount_amount - ($oldSubtotal * (float) $order->discount_percent / 100));

                // Return the whole previous product stock before re-deducting the
                // replacement items so only the net difference stays consumed.
                $this->stock->restoreOrderStock((int) $order->id, null, (int) $user->id);

                foreach ($order->items as $oldItem) {
                    $this->restoreInventoryForProduct($oldItem->product_id, $oldItem->quantity, $user->id, $order->id);
                    Product::whereKey($oldItem->product_id)
                        ->where('sales_count', '>=', $oldItem->quantity)
                        ->decrement('sales_count', $oldItem->quantity);
                    $oldItem->modifiers()->delete();
                    $oldItem->delete();
                }

                $subtotal = 0;
                foreach ($validated['items'] as $sort => $itemData) {
                    $product = Product::where('restaurant_id', $order->restaurant_id)
                        ->where('is_available', true)
                        ->findOrFail($itemData['product_id']);

                    // Determine unit type and price
                    $unitType = $itemData['unit_type'] ?? 'main';
                    $conversionFactor = (float) ($itemData['conversion_factor'] ?? $product->conversion_factor ?? 1);
                    $subUnitPrice = (float) ($itemData['sub_unit_price'] ?? $product->sub_unit_price ?? 0);

                    if ($unitType === 'sub' && $product->sub_unit && $conversionFactor > 0) {
                        $unitPrice = $subUnitPrice > 0 ? $subUnitPrice : (($this->calculateItemPrice($product, $itemData)) / $conversionFactor);
                        $stockDeductionQty = (float) $itemData['quantity'] / $conversionFactor;
                        $baseQuantity = (float) $itemData['quantity'];
                    } else {
                        $unitPrice = $this->calculateItemPrice($product, $itemData);
                        $stockDeductionQty = (float) $itemData['quantity'];
                        $baseQuantity = (float) $itemData['quantity'] * $conversionFactor;
                        $unitType = 'main';
                    }

                    $quantity = $itemData['quantity'];
                    $subtotal += $unitPrice * $quantity;

                    $item = $order->items()->create([
                        'product_id' => $product->id,
                        'size_id' => $itemData['size_id'] ?? null,
                        'quantity' => $quantity,
                        'unit_type' => $unitType,
                        'unit_name' => $itemData['unit_name'] ?? ($unitType === 'sub' ? $product->sub_unit : $product->sale_unit),
                        'conversion_factor' => $conversionFactor,
                        'base_quantity' => $baseQuantity,
                        'unit_price' => $unitPrice,
                        'discount_amount' => 0,
                        'tax_amount' => 0,
                        'total_price' => $unitPrice * $quantity,
                        'notes' => $itemData['notes'] ?? null,
                        'kitchen_status' => 'pending',
                        'sort_order' => $sort,
                    ]);
                    if ($product->kitchen_station_id) {
                        OrderKitchenStation::create([
                            'order_item_id' => $item->id,
                            'station_id' => $product->kitchen_station_id,
                        ]);
                    }

                    foreach ($itemData['modifier_options'] ?? [] as $modifierOption) {
                        $option = ModifierOption::findOrFail($modifierOption['modifier_option_id']);
                        OrderItemModifier::create([
                            'order_item_id' => $item->id,
                            'modifier_id' => $modifierOption['modifier_id'],
                            'modifier_option_id' => $option->id,
                            'price_modifier' => $option->price_modifier,
                        ]);
                    }

                    $this->stock->deductFefo(
                        (int) $order->restaurant_id,
                        $order->warehouse_id ? (int) $order->warehouse_id : null,
                        $product,
                        $stockDeductionQty,
                        (int) $user->id,
                        'order',
                        (int) $order->id
                    );
                    $this->deductInventoryForProduct($product->id, $stockDeductionQty, $user->id, $order->id);
                    $product->increment('sales_count', (int) round($baseQuantity));
                }

                $discount = min($subtotal, $fixedDiscount + ($subtotal * $discountPercent / 100));
                $taxRate = (float) ($restaurant?->tax_rate ?? 0);
                $tax = ($subtotal - $discount) * $taxRate;
                $total = $subtotal - $discount + $tax + (float) $order->delivery_fee;

                $updates = array_merge($updates, [
                    'subtotal' => $subtotal,
                    'discount_amount' => $discount,
                    'discount_percent' => $discountPercent,
                    'tax_amount' => $tax,
                    'total_amount' => $total,
                ]);
            }

            // Existing payments are only replaced when the caller explicitly sends
            // the full list of payment methods. This keeps previously recorded
            // payments (e.g. cash) intact when an extra method is paid later.
            if (! empty($requestedMethods)) {
                $paymentTotals = $this->distributePaymentAmounts(
                    $requestedMethods,
                    $amountReceived ?? ($total > 0 ? $total : (float) $order->amount_paid)
                );

                Payment::where('paymentable_type', Order::class)
                    ->where('paymentable_id', $order->id)
                    ->delete();

                foreach ($paymentTotals as $method => $amount) {
                    if ($amount <= 0) {
                        continue;
                    }
                    app(JournalPostingService::class)
                        ->assertConfiguredPaymentMethod($order->restaurant_id, $method);
                    Payment::create([
                        'restaurant_id' => $order->restaurant_id,
                        'paymentable_type' => Order::class,
                        'paymentable_id' => $order->id,
                        'amount' => $amount,
                        'method' => $method,
                        'reference' => null,
                        'transaction_id' => null,
                        'paid_at' => now(),
                        'user_id' => $request->user()->id,
                        'notes' => 'تعديل طلب - طريقة دفع',
                    ]);
                }

                $amountPaid = array_sum($paymentTotals);
                $updates['payment_methods'] = array_keys($paymentTotals);
                $updates['payment_method'] = array_key_first($paymentTotals)
                    ?? $singlePaymentMethod
                    ?? $order->payment_method;
            } else {
                $method = $singlePaymentMethod ?? $order->payment_method ?? 'cash';

                // Changing only the payment method should move the recorded
                // payment rows too, not just the order column.
                if ($singlePaymentMethod !== null) {
                    app(JournalPostingService::class)
                        ->assertConfiguredPaymentMethod($order->restaurant_id, $singlePaymentMethod);
                    Payment::where('paymentable_type', Order::class)
                        ->where('paymentable_id', $order->id)
                        ->where('amount', '>', 0)
                        ->update(['method' => $singlePaymentMethod, 'updated_at' => now()]);
                    $updates['payment_method'] = $singlePaymentMethod;
                }

                $amountPaid = (float) $order->amount_paid;
                $netPaid = max(0, $amountPaid - (float) $order->change_due);

                if (isset($validated['items'])) {
                    // Item replacement can refund a surplus or collect the
                    // difference between the old and the new total.
                    if ($netPaid > $total) {
                        Payment::create([
                            'restaurant_id' => $order->restaurant_id,
                            'paymentable_type' => Order::class,
                            'paymentable_id' => $order->id,
                            'amount' => -round($netPaid - $total, 2),
                            'method' => $method,
                            'reference' => null,
                            'transaction_id' => null,
                            'paid_at' => now(),
                            'user_id' => $request->user()->id,
                            'notes' => 'استرداد فرق تعديل أصناف الطلب',
                        ]);
                        $amountPaid = $total;
                    } elseif ($netPaid < $total && $amountReceived !== null && $amountReceived > 0) {
                        Payment::create([
                            'restaurant_id' => $order->restaurant_id,
                            'paymentable_type' => Order::class,
                            'paymentable_id' => $order->id,
                            'amount' => $amountReceived,
                            'method' => $method,
                            'reference' => null,
                            'transaction_id' => null,
                            'paid_at' => now(),
                            'user_id' => $request->user()->id,
                            'notes' => 'دفعة فرق تعديل أصناف الطلب',
                        ]);
                        $amountPaid = $netPaid + $amountReceived;
                    } else {
                        $amountPaid = $netPaid;
                    }
                } elseif ($amountReceived !== null) {
                    // Allow callers to move the recorded paid amount explicitly. A
                    // positive difference creates a new payment row so the revenue
                    // breakdown stays accurate; existing rows are never mutated.
                    $delta = round($amountReceived - $amountPaid, 2);
                    if ($delta > 0) {
                        app(JournalPostingService::class)
                            ->assertConfiguredPaymentMethod($order->restaurant_id, $method);
                        Payment::create([
                            'restaurant_id' => $order->restaurant_id,
                            'paymentable_type' => Order::class,
                            'paymentable_id' => $order->id,
                            'amount' => $delta,
                            'method' => $method,
                            'reference' => null,
                            'transaction_id' => null,
                            'paid_at' => now(),
                            'user_id' => $request->user()->id,
                            'notes' => 'تعديل طلب - تسوية دفعة',
                        ]);
                        $updates['payment_method'] = $method;
                    }
                    $amountPaid = $amountReceived;
                }
            }

            $updates['amount_paid'] = $amountPaid;
            $updates['change_due'] = max(0, $amountPaid - $total);
            $updates['payment_status'] = $total > 0 && $amountPaid >= $total
                ? 'paid'
                : ($amountPaid > 0 ? 'partial' : 'unpaid');

            $order->update($updates);

            return response()->json([
                'order' => new OrderResource($order->fresh()->load([
                    'items.product', 'items.size', 'items.modifiers.modifier',
                    'items.modifiers.modifierOption', 'payments', 'table', 'customer', 'cashier', 'waiter',
                ])),
                'message' => 'تم تحديث الطلب',
            ]);
        });
    }

    public function destroy(Order $order)
    {
        $this->authorizeOrder($order, request(), 'delete_orders');
        if ((float) $order->amount_paid > 0) {
            return response()->json(['message' => 'استرداد قيمة الطلب قبل حذفه'], 400);
        }
        if ($order->status !== 'cancelled' && $order->payment_status !== 'refunded') {
            $this->stock->restoreOrderStock((int) $order->id, null, (int) auth()->id());
            foreach ($order->items as $item) {
                $restoreQty = ($item->unit_type === 'sub' && $item->conversion_factor > 0)
                    ? (float) $item->quantity / (float) $item->conversion_factor
                    : (float) $item->quantity;
                $this->restoreInventoryForProduct($item->product_id, $restoreQty, auth()->id(), $order->id);
                Product::whereKey($item->product_id)
                    ->where('sales_count', '>=', (int) round($item->base_quantity ?? $item->quantity))
                    ->decrement('sales_count', (int) round($item->base_quantity ?? $item->quantity));
            }
        }
        if ($order->table_id) {
            Table::whereKey($order->table_id)->update(['status' => 'available']);
        }
        $order->delete();

        return response()->json(['message' => 'تم حذف الطلب']);
    }

    public function refund(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request, 'refund_orders');
        $refundableAmount = max(0, (float) $order->amount_paid - (float) $order->change_due);
        if (! in_array($order->payment_status, ['paid', 'partial'], true) || $refundableAmount <= 0) {
            return response()->json(['message' => 'لا يمكن استرجاع دفع غير مدفوع'], 400);
        }
        $user = $request->user();
        $validated = $request->validate([
            'reason' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0.01',
        ]);
        $requestedAmount = isset($validated['amount'])
            ? (float) $validated['amount']
            : $refundableAmount;
        if ($requestedAmount > $refundableAmount + 0.0001) {
            return response()->json(['message' => 'مبلغ الاسترداد أكبر من المبلغ المتاح'], 400);
        }
        $refundAmount = min($requestedAmount, $refundableAmount);
        $isFullRefund = $refundAmount >= $refundableAmount - 0.0001;

        return DB::transaction(function () use ($order, $user, $validated, $refundAmount, $isFullRefund) {
            if ($order->payment_status === 'refunded') {
                return response()->json(['message' => 'تم استرداد هذا الطلب مسبقاً'], 400);
            }
            $oldStatus = $order->status;
            if ($isFullRefund) {
                if ($oldStatus !== 'cancelled') {
                    $this->stock->restoreOrderStock((int) $order->id, null, (int) $user->id);
                    foreach ($order->items as $item) {
                        $restoreQty = ($item->unit_type === 'sub' && $item->conversion_factor > 0)
                            ? (float) $item->quantity / (float) $item->conversion_factor
                            : (float) $item->quantity;
                        $this->restoreInventoryForProduct($item->product_id, $restoreQty, $user->id, $order->id);
                        Product::where('id', $item->product_id)
                            ->where('sales_count', '>=', (int) round($item->base_quantity ?? $item->quantity))
                            ->decrement('sales_count', (int) round($item->base_quantity ?? $item->quantity));
                    }
                    if ($order->table_id) {
                        Table::where('id', $order->table_id)->update(['status' => 'available']);
                    }
                }
                $order->update([
                    'status' => 'cancelled',
                    'payment_status' => 'refunded',
                    'amount_paid' => 0,
                    'cancelled_at' => now(),
                    'cancelled_by' => $user->id,
                    'cancellation_reason' => $validated['reason'] ?? 'استرجاع',
                ]);
                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'old_status' => $oldStatus,
                    'new_status' => 'cancelled',
                    'changed_by' => $user->id,
                    'notes' => 'استرجاع دفع: '.($validated['reason'] ?? 'بدون سبب'),
                ]);
            } else {
                $remainingPaid = max(0, (float) $order->amount_paid - $refundAmount);
                $paymentStatus = $remainingPaid >= (float) $order->total_amount
                    ? 'paid'
                    : ($remainingPaid > 0 ? 'partial' : 'unpaid');
                $order->update([
                    'amount_paid' => $remainingPaid,
                    'payment_status' => $paymentStatus,
                ]);
            }
            Payment::create([
                'restaurant_id' => $order->restaurant_id,
                'paymentable_type' => Order::class,
                'paymentable_id' => $order->id,
                'amount' => -$refundAmount,
                'method' => $order->payment_method ?: 'cash',
                'paid_at' => now(),
                'user_id' => $user->id,
                'notes' => ($isFullRefund ? 'استرداد كامل للطلب: ' : 'استرداد جزئي للطلب: ').($validated['reason'] ?? ''),
            ]);

            return response()->json([
                'order' => $order->fresh(),
                'message' => $isFullRefund
                    ? 'تم استرجاع الدفع وإلغاء الطلب'
                    : 'تم استرداد المبلغ جزئياً',
            ]);
        });
    }

    public function cancel(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request, 'cancel_orders');
        if (! in_array($order->status, ['pending', 'confirmed', 'preparing'])) {
            return response()->json(['message' => 'لا يمكن إلغاء الطلب في حالته الحالية'], 400);
        }
        $user = $request->user();
        $validated = $request->validate([
            'reason' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($order, $user, $validated) {
            // Restore warehouse stock and legacy recipe inventory.
            $this->stock->restoreOrderStock((int) $order->id, null, (int) $user->id);
            foreach ($order->items as $item) {
                $restoreQty = ($item->unit_type === 'sub' && $item->conversion_factor > 0)
                    ? (float) $item->quantity / (float) $item->conversion_factor
                    : (float) $item->quantity;
                $this->restoreInventoryForProduct($item->product_id, $restoreQty, $user->id, $order->id);
                Product::where('id', $item->product_id)
                    ->where('sales_count', '>=', (int) round($item->base_quantity ?? $item->quantity))
                    ->decrement('sales_count', (int) round($item->base_quantity ?? $item->quantity));
            }

            // Free table
            if ($order->table_id) {
                Table::where('id', $order->table_id)->update(['status' => 'available']);
            }

            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $validated['reason'] ?? null,
            ]);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'old_status' => $order->getOriginal('status'),
                'new_status' => 'cancelled',
                'changed_by' => $user->id,
                'notes' => 'إلغاء الطلب: '.($validated['reason'] ?? 'بدون سبب'),
            ]);

            return response()->json(['order' => $order->fresh(), 'message' => 'تم إلغاء الطلب']);
        });
    }

    public function updateStatus(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,confirmed,preparing,ready,served,completed',
            'notes' => 'nullable|string',
        ]);

        if ($validated['status'] === 'served' && $request->user()->hasPermissionTo('update_kitchen_order', 'sanctum')) {
            abort_unless($order->restaurant_id === $request->user()->restaurant_id, 404);
            abort_unless(
                ! $order->items()
                    ->where(function ($query) {
                        $query->whereNull('kitchen_status')
                            ->orWhereNotIn('kitchen_status', ['ready', 'served']);
                    })
                    ->exists(),
                422,
                'لا يمكن تسليم الطلب قبل جاهزية جميع الأصناف.'
            );
            if (! $request->user()->hasPermissionTo('view_all_kitchen_orders', 'sanctum')
                && $request->user()->hasPermissionTo('view_station_kitchen_orders', 'sanctum')) {
                abort_unless(
                    $request->user()->kitchen_station_id
                        && $order->items()->whereHas('kitchenStations', fn ($query) => $query
                            ->where('station_id', $request->user()->kitchen_station_id))
                            ->exists(),
                    403
                );
            }
        } else {
            $this->authorizeOrder($order, $request, 'edit_orders');
        }

        $oldStatus = $order->status;
        $order->update(['status' => $validated['status']]);

        if ($validated['status'] === 'completed') {
            $order->completed_at = now();
        }

        // Table stays occupied after serving; it is released manually or by the
        // configured auto-release timer (see TableController::index).

        $order->save();

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'old_status' => $oldStatus,
            'new_status' => $validated['status'],
            'changed_by' => $request->user()->id,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json(['order' => $order->fresh(), 'message' => 'تم تحديث حالة الطلب']);
    }

    /**
     * Replace the payment breakdown of an order.
     *
     * This is deliberately separate from the general order update so the
     * dedicated "edit_order_payment" permission can control it.
     */
    public function updatePayments(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request, 'edit_order_payment');
        if (in_array($order->status, ['cancelled'], true) || $order->payment_status === 'refunded') {
            return response()->json(['message' => 'لا يمكن تعديل دفعات طلب ملغي أو مسترد'], 400);
        }

        $validated = $request->validate([
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|string|max:50',
            'payments.*.amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $entries = collect($validated['payments'])
            ->map(fn (array $entry): array => [
                'method' => trim((string) $entry['method']),
                'amount' => round((float) $entry['amount'], 2),
            ])
            ->filter(fn (array $entry): bool => $entry['method'] !== '' && $entry['amount'] > 0)
            ->values();

        if ($entries->isEmpty()) {
            return response()->json(['message' => 'أضف دفعة واحدة على الأقل بمبلغ أكبر من صفر'], 422);
        }

        return DB::transaction(function () use ($order, $request, $entries, $validated) {
            $total = (float) $order->total_amount;

            foreach ($entries as $entry) {
                app(JournalPostingService::class)
                    ->assertConfiguredPaymentMethod($order->restaurant_id, $entry['method']);
            }

            // Replace the full breakdown so the order reflects exactly what the
            // user entered (one row per method, preserving duplicates by merging).
            $totalsByMethod = [];
            foreach ($entries as $entry) {
                $totalsByMethod[$entry['method']] = round(
                    ($totalsByMethod[$entry['method']] ?? 0) + $entry['amount'],
                    2
                );
            }

            Payment::where('paymentable_type', Order::class)
                ->where('paymentable_id', $order->id)
                ->delete();

            foreach ($totalsByMethod as $method => $amount) {
                Payment::create([
                    'restaurant_id' => $order->restaurant_id,
                    'paymentable_type' => Order::class,
                    'paymentable_id' => $order->id,
                    'amount' => $amount,
                    'method' => $method,
                    'reference' => null,
                    'transaction_id' => null,
                    'paid_at' => now(),
                    'user_id' => $request->user()->id,
                    'notes' => $validated['notes'] ?? 'تعديل دفعات الطلب',
                ]);
            }

            $amountPaid = array_sum($totalsByMethod);
            $paymentMethods = array_keys($totalsByMethod);

            $order->update([
                'payment_method' => $paymentMethods[0] ?? $order->payment_method,
                'payment_methods' => $paymentMethods,
                'amount_paid' => $amountPaid,
                'change_due' => max(0, $amountPaid - $total),
                'payment_status' => $total > 0 && $amountPaid >= $total
                    ? 'paid'
                    : ($amountPaid > 0 ? 'partial' : 'unpaid'),
            ]);

            return response()->json([
                'order' => new OrderResource($order->fresh()->load([
                    'items.product', 'items.size', 'items.modifiers.modifier',
                    'items.modifiers.modifierOption', 'payments', 'table', 'customer', 'cashier', 'waiter',
                ])),
                'message' => 'تم تحديث دفعات الطلب',
            ]);
        }, 5);
    }

    public function recordPayment(Request $request, Order $order)
    {
        $this->authorizeOrder($order, $request, ['edit_orders', 'edit_order_payment']);
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'method' => 'required|string|max:50',
            'reference' => 'nullable|string',
            'transaction_id' => 'nullable|string',
        ]);
        app(JournalPostingService::class)
            ->assertConfiguredPaymentMethod($order->restaurant_id, $validated['method']);

        return DB::transaction(function () use ($order, $request, $validated) {
            $payment = Payment::create([
                'restaurant_id' => $order->restaurant_id,
                'paymentable_type' => Order::class,
                'paymentable_id' => $order->id,
                'amount' => $validated['amount'],
                'method' => $validated['method'],
                'reference' => $validated['reference'] ?? null,
                'transaction_id' => $validated['transaction_id'] ?? null,
                'paid_at' => now(),
                'user_id' => $request->user()->id,
                'notes' => null,
            ]);

            $newPaid = $order->amount_paid + $validated['amount'];
            $paymentStatus = $newPaid >= $order->total_amount ? 'paid' : 'partial';
            $order->update(['amount_paid' => $newPaid, 'payment_status' => $paymentStatus]);

            return response()->json(['payment' => $payment, 'order' => $order->fresh(), 'message' => 'تم تسجيل الدفعة']);
        }, 5);
    }

    private function distributePaymentAmounts(array $entries, float $pool): array
    {
        $result = [];
        $missing = [];
        $providedSum = 0.0;

        foreach ($entries as $entry) {
            $method = $entry['method'];
            $amount = $entry['amount'];

            if ($amount === null) {
                $missing[] = $method;
                continue;
            }

            $providedSum += $amount;
            $result[$method] = ($result[$method] ?? 0) + $amount;
        }

        if (empty($missing)) {
            return $result;
        }

        $remaining = max(0, round($pool - $providedSum, 2));
        $count = count($missing);

        foreach ($missing as $index => $method) {
            $left = $count - $index;
            $share = $index === $count - 1
                ? $remaining
                : round($remaining / $left, 2);
            $result[$method] = ($result[$method] ?? 0) + $share;
            $remaining = round($remaining - $share, 2);
        }

        return $result;
    }

    private function authorizeOrder(Order $order, Request $request, string|array $permission): void
    {
        $user = $request->user();
        abort_unless($order->restaurant_id === $user->restaurant_id, 403);
        $allowed = collect((array) $permission)
            ->contains(fn (string $name): bool => $user->hasPermissionTo($name, 'sanctum'));
        abort_unless($allowed, 403);
        abort_if(
            (int) $order->cashier_id !== (int) $user->id
                && ! $user->hasPermissionTo('view_all_orders', 'sanctum'),
            403
        );
    }

    protected function deductInventoryForProduct($productId, $qty, $userId, $orderId)
    {
        $recipe = Recipe::where('product_id', $productId)->where('is_active', true)->first();
        if (! $recipe) {
            return;
        }

        foreach ($recipe->ingredients as $ingredient) {
            $needed = (float) $ingredient->quantity * (float) $qty;
            $item = InventoryItem::find($ingredient->inventory_item_id);
            if (! $item) {
                continue;
            }
            $item->decrement('current_stock', $needed);
            StockMovement::create([
                'restaurant_id' => $item->restaurant_id,
                'inventory_item_id' => $item->id,
                'type' => 'out',
                'quantity' => $needed,
                'cost_per_unit' => $ingredient->unit_cost,
                'total_cost' => $needed * $ingredient->unit_cost,
                'reference_type' => 'order',
                'reference_id' => $orderId,
                'user_id' => $userId,
                'notes' => "صرف لوصفة منتج #{$productId}",
            ]);
        }
    }

    protected function calculateItemPrice(Product $product, array $item): float
    {
        $price = (float) $product->sale_price;

        if (! empty($item['size_id'])) {
            $size = $product->sizes()->whereKey($item['size_id'])->first();
            if (! $size) {
                abort(422, 'المقاس المحدد لا يتبع المنتج');
            }
            $price += (float) $size->price_modifier;
        }

        foreach ($item['modifier_options'] ?? [] as $modifierOption) {
            $modifier = ProductModifier::where('product_id', $product->id)
                ->whereKey($modifierOption['modifier_id'])
                ->first();
            $option = ModifierOption::where('modifier_id', $modifierOption['modifier_id'])
                ->whereKey($modifierOption['modifier_option_id'])
                ->first();
            if (! $modifier || ! $option) {
                abort(422, 'الإضافة المحددة لا تتبع المنتج');
            }
            $price += (float) $option->price_modifier;
        }

        return $price;
    }

    private function nextOrderNumber(): string
    {
        $now = Carbon::now();
        $date = $now->format('Ymd');
        $sequenceDate = $now->toDateString();

        DB::table('order_number_sequences')->insertOrIgnore([
            'sequence_date' => $sequenceDate,
            'last_sequence' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sequence = DB::table('order_number_sequences')
            ->where('sequence_date', $sequenceDate)
            ->lockForUpdate()
            ->first();

        $latestExistingNumber = DB::table('orders')
            ->where('order_number', 'like', "ORD-{$date}-%")
            ->orderByRaw('LENGTH(order_number) DESC')
            ->orderByDesc('order_number')
            ->value('order_number');
        $existingSequence = $latestExistingNumber
            ? (int) substr($latestExistingNumber, strrpos($latestExistingNumber, '-') + 1)
            : 0;
        $nextSequence = max((int) $sequence->last_sequence, $existingSequence) + 1;

        DB::table('order_number_sequences')
            ->where('id', $sequence->id)
            ->update([
                'last_sequence' => $nextSequence,
                'updated_at' => $now,
            ]);

        return "ORD-{$date}-".str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);
    }

    protected function restoreInventoryForProduct($productId, $qty, $userId, $orderId)
    {
        $recipe = Recipe::where('product_id', $productId)->where('is_active', true)->first();
        if (! $recipe) {
            return;
        }
        foreach ($recipe->ingredients as $ingredient) {
            $restored = (float) $ingredient->quantity * (float) $qty;
            $item = InventoryItem::find($ingredient->inventory_item_id);
            if (! $item) {
                continue;
            }
            $item->increment('current_stock', $restored);
            StockMovement::create([
                'restaurant_id' => $item->restaurant_id,
                'inventory_item_id' => $item->id,
                'type' => 'in',
                'quantity' => $restored,
                'cost_per_unit' => $ingredient->unit_cost,
                'total_cost' => $restored * $ingredient->unit_cost,
                'reference_type' => 'order_cancel',
                'reference_id' => $orderId,
                'user_id' => $userId,
                'notes' => "استعادة مخزون عند إلغاء طلب #{$orderId}",
            ]);
        }
    }
}
