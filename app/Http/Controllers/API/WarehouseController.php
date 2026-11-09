<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Services\StockService;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductStockMovement;
use App\Models\Setting;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseController extends Controller
{
    protected StockService $stock;

    public function __construct(StockService $stock)
    {
        $this->stock = $stock;
    }

    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        $query = Warehouse::where('restaurant_id', $restaurantId)
            ->with(['batches' => fn ($query) => $query->select(
                'id', 'warehouse_id', 'product_id', 'quantity', 'cost_price', 'expiry_date'
            )]);

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        $warehouses = $query
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Warehouse $warehouse) => $this->warehousePayload($warehouse));

        return response()->json(['warehouses' => $warehouses]);
    }

    public function show(Warehouse $warehouse)
    {
        $this->authorizeWarehouse($warehouse);

        $warehouse->load(['batches' => fn ($query) => $query
            ->where('quantity', '>', 0)
            ->with('product:id,name,sku,barcode')]);

        return response()->json([
            'warehouse' => $this->warehousePayload($warehouse),
            'batches' => $warehouse->batches,
        ]);
    }

    public function store(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $validated = $this->validateWarehouse($request, $restaurantId);

        if (! empty($validated['is_default'])) {
            Warehouse::where('restaurant_id', $restaurantId)->update(['is_default' => false]);
        }

        $warehouse = Warehouse::create(array_merge($validated, [
            'restaurant_id' => $restaurantId,
        ]));

        return response()->json([
            'warehouse' => $warehouse,
            'message' => 'تم إضافة المخزن بنجاح',
        ], 201);
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $this->authorizeWarehouse($warehouse);

        $validated = $this->validateWarehouse($request, $warehouse->restaurant_id, $warehouse->id);

        if (! empty($validated['is_default'])) {
            Warehouse::where('restaurant_id', $warehouse->restaurant_id)
                ->whereKeyNot($warehouse->id)
                ->update(['is_default' => false]);
        }

        $warehouse->update($validated);

        return response()->json([
            'warehouse' => $warehouse->fresh(),
            'message' => 'تم تحديث المخزن بنجاح',
        ]);
    }

    public function destroy(Warehouse $warehouse)
    {
        $this->authorizeWarehouse($warehouse);

        $hasStock = ProductBatch::where('warehouse_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->exists();

        if ($hasStock) {
            return response()->json([
                'message' => 'لا يمكن حذف مخزن يحتوي على أرصدة. انقل الأرصدة أولاً.',
            ], 422);
        }

        $warehouse->delete();

        return response()->json(['message' => 'تم حذف المخزن بنجاح']);
    }

    /**
     * Stock levels per product, optionally scoped to one warehouse.
     */
    public function stock(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $settings = $this->alertSettings($restaurantId);
        $threshold = $settings['low_stock_threshold'];

        $query = Product::where('restaurant_id', $restaurantId)
            ->with([
                'category:id,name,color',
                'batches' => function ($batchQuery) use ($warehouseId) {
                    if ($warehouseId) {
                        $batchQuery->where('warehouse_id', $warehouseId);
                    }
                },
            ]);

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->boolean('low_stock')) {
            $warehouseClause = $warehouseId ? ' AND warehouse_id = '.(int) $warehouseId : '';
            $query->where('track_inventory', true)
                ->whereRaw(
                    '(SELECT COALESCE(SUM(quantity), 0) FROM product_batches WHERE product_batches.product_id = products.id AND product_batches.deleted_at IS NULL'.$warehouseClause.') <= GREATEST(products.min_stock, '.(int) $threshold.')'
                );
        }

        if ($request->boolean('expired')) {
            $query->whereHas('batches', function ($batchQuery) use ($warehouseId) {
                $batchQuery->where('quantity', '>', 0)
                    ->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '<', now()->toDateString());
                if ($warehouseId) {
                    $batchQuery->where('warehouse_id', $warehouseId);
                }
            });
        }

        $products = $query->orderBy('name')->paginate(min(max($request->integer('per_page', 20), 1), 100));

        $products->getCollection()->transform(function (Product $product) use ($threshold) {
            $batches = $product->batches;
            $stock = (float) $batches->sum('quantity');
            $expired = $batches->filter(fn ($batch) => $batch->expiry_date && $batch->expiry_date->lt(today()))->sum('quantity');
            $near = $batches->filter(
                fn ($batch) => $batch->expiry_date
                    && $batch->expiry_date->gte(today())
                    && $batch->expiry_date->lte(today()->addDays(30))
            )->sum('quantity');
            $nearestExpiry = $batches
                ->filter(fn ($batch) => $batch->expiry_date && (float) $batch->quantity > 0)
                ->sortBy(fn ($batch) => $batch->expiry_date->timestamp)
                ->first()?->expiry_date;

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'image' => $product->image,
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                ] : null,
                'sale_price' => (float) $product->sale_price,
                'cost_price' => (float) $product->cost_price,
                'track_inventory' => (bool) $product->track_inventory,
                'stock' => $stock,
                'min_stock' => (int) $product->min_stock,
                'low_stock' => (bool) $product->track_inventory
                    && $stock <= max((int) $product->min_stock, $threshold),
                'expired_quantity' => (float) $expired,
                'near_expiry_quantity' => (float) $near,
                'nearest_expiry' => $nearestExpiry?->toDateString(),
                'batches_count' => $batches->where('quantity', '>', 0)->count(),
            ];
        });

        return response()->json($products);
    }

    public function batches(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = ProductBatch::where('restaurant_id', $restaurantId)
            ->with(['product:id,name,sku,barcode', 'warehouse:id,name', 'supplier:id,name']);

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }
        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('batch_number', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($productQuery) => $productQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%"));
            });
        }
        if ($request->filled('status')) {
            $today = today();
            match ((string) $request->string('status')) {
                'expired' => $query->whereNotNull('expiry_date')->whereDate('expiry_date', '<', $today),
                'near_expiry' => $query->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '>=', $today)
                    ->whereDate('expiry_date', '<=', $today->copy()->addDays(30)),
                'valid' => $query->where(function ($q) use ($today) {
                    $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>', $today->copy()->addDays(30));
                }),
                default => null,
            };
        }
        if ($request->boolean('in_stock')) {
            $query->where('quantity', '>', 0);
        }

        $batches = $query->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 30), 1), 100));

        return response()->json($batches);
    }

    public function stockIn(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $validated = $request->validate([
            'warehouse_id' => [
                'required',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('restaurant_id', $restaurantId)),
            ],
            'product_id' => [
                'required',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('restaurant_id', $restaurantId)),
            ],
            'quantity' => 'required|numeric|min:0.001',
            'cost_price' => 'nullable|numeric|min:0',
            'expiry_date' => 'nullable|date',
            'batch_number' => 'nullable|string|max:100',
            'supplier_id' => [
                'nullable',
                Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('restaurant_id', $restaurantId)),
            ],
            'notes' => 'nullable|string',
        ]);

        $batch = $this->stock->stockIn(
            $restaurantId,
            (int) $validated['warehouse_id'],
            (int) $validated['product_id'],
            (float) $validated['quantity'],
            $validated,
            $request->user()->id,
            'manual',
            null
        );

        return response()->json([
            'batch' => $batch,
            'message' => 'تم إدخال المخزون بنجاح',
        ], 201);
    }

    public function transfer(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $validated = $request->validate([
            'from_warehouse_id' => [
                'required',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('restaurant_id', $restaurantId)),
            ],
            'to_warehouse_id' => [
                'required',
                'different:from_warehouse_id',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('restaurant_id', $restaurantId)),
            ],
            'product_id' => [
                'required',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('restaurant_id', $restaurantId)),
            ],
            'quantity' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string',
        ]);

        $product = Product::where('restaurant_id', $restaurantId)->findOrFail($validated['product_id']);

        $this->stock->transfer(
            $restaurantId,
            (int) $validated['from_warehouse_id'],
            (int) $validated['to_warehouse_id'],
            $product,
            (float) $validated['quantity'],
            $request->user()->id,
            $validated['notes'] ?? null
        );

        return response()->json(['message' => 'تم تحويل المخزون بنجاح']);
    }

    public function adjust(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $validated = $request->validate([
            'batch_id' => [
                'required',
                Rule::exists('product_batches', 'id')->where(fn ($query) => $query->where('restaurant_id', $restaurantId)),
            ],
            'quantity' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $batch = $this->stock->adjustBatch(
            (int) $validated['batch_id'],
            (float) $validated['quantity'],
            $request->user()->id,
            $validated['notes'] ?? null
        );

        return response()->json(['batch' => $batch, 'message' => 'تم تسوية المخزون بنجاح']);
    }

    public function movements(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = ProductStockMovement::where('restaurant_id', $restaurantId)
            ->with(['product:id,name', 'warehouse:id,name', 'user:id,name']);

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        return response()->json($query->orderByDesc('id')->paginate(30));
    }

    /**
     * Live product alerts for the bell and alerts page.
     */
    public function alerts(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $settings = $this->alertSettings($restaurantId);

        $expired = collect();
        $nearExpiry = collect();
        $lowStock = collect();

        if ($settings['enable_expiry_alerts']) {
            $expired = ProductBatch::where('restaurant_id', $restaurantId)
                ->where('quantity', '>', 0)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<', now()->toDateString())
                ->with(['product:id,name,sku,barcode,image', 'warehouse:id,name'])
                ->orderBy('expiry_date')
                ->get()
                ->map(function (ProductBatch $batch) {
                    $quantity = (float) $batch->quantity;
                    $cost = (float) $batch->cost_price;

                    return [
                        'id' => $batch->id,
                        'product_id' => $batch->product_id,
                        'product_name' => $batch->product?->name,
                        'barcode' => $batch->product?->barcode,
                        'warehouse_id' => $batch->warehouse_id,
                        'warehouse_name' => $batch->warehouse?->name,
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
        }

        if ($settings['enable_near_expiry_alerts']) {
            $nearExpiry = ProductBatch::where('restaurant_id', $restaurantId)
                ->where('quantity', '>', 0)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', now()->toDateString())
                ->whereDate('expiry_date', '<=', now()->addDays($settings['near_expiry_days'])->toDateString())
                ->with(['product:id,name,sku,barcode,image', 'warehouse:id,name'])
                ->orderBy('expiry_date')
                ->get()
                ->map(function (ProductBatch $batch) {
                    $quantity = (float) $batch->quantity;
                    $cost = (float) $batch->cost_price;

                    return [
                        'id' => $batch->id,
                        'product_id' => $batch->product_id,
                        'product_name' => $batch->product?->name,
                        'barcode' => $batch->product?->barcode,
                        'warehouse_id' => $batch->warehouse_id,
                        'warehouse_name' => $batch->warehouse?->name,
                        'batch_number' => $batch->batch_number,
                        'quantity' => $quantity,
                        'cost_price' => $cost,
                        'value' => round($quantity * $cost, 2),
                        'expiry_date' => $batch->expiry_date?->toDateString(),
                        'days_left' => $batch->expiry_date
                            ? (int) floor(($batch->expiry_date->startOfDay()->timestamp - now()->startOfDay()->timestamp) / 86400)
                            : null,
                    ];
                });
        }

        if ($settings['enable_low_stock_alerts']) {
            $threshold = $settings['low_stock_threshold'];

            $lowStock = Product::where('restaurant_id', $restaurantId)
                ->where('track_inventory', true)
                ->with(['batches' => fn ($query) => $query->select('id', 'product_id', 'warehouse_id', 'quantity', 'expiry_date')])
                ->get()
                ->map(function (Product $product) use ($threshold) {
                    $stock = (float) $product->batches->sum('quantity');
                    $minimum = max((int) $product->min_stock, (int) $product->low_stock_threshold, $threshold);

                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'barcode' => $product->barcode,
                        'image' => $product->image,
                        'stock' => $stock,
                        'min_stock' => (int) $product->min_stock,
                        'threshold' => $minimum,
                        'shortage' => max(0, round($minimum - $stock, 3)),
                    ];
                })
                ->filter(fn ($row) => $row['stock'] <= $row['threshold'])
                ->sortBy('stock')
                ->values();
        }

        $summary = [
            'expired_lots' => $expired->count(),
            'expired_quantity' => (float) $expired->sum('quantity'),
            'total_loss' => round((float) $expired->sum('loss'), 2),
            'near_expiry_lots' => $nearExpiry->count(),
            'near_expiry_quantity' => (float) $nearExpiry->sum('quantity'),
            'low_stock_products' => $lowStock->count(),
        ];
        $summary['total_alerts'] = $summary['expired_lots']
            + $summary['near_expiry_lots']
            + $summary['low_stock_products'];

        return response()->json([
            'settings' => $settings,
            'summary' => $summary,
            'expired' => $expired->values(),
            'near_expiry' => $nearExpiry->values(),
            'low_stock' => $lowStock->values(),
            'generated_at' => now()->toISOString(),
        ]);
    }

    protected function warehousePayload(Warehouse $warehouse): array
    {
        $batches = $warehouse->batches;
        $today = today();

        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'code' => $warehouse->code,
            'type' => $warehouse->type,
            'allow_sales' => (bool) $warehouse->allow_sales,
            'pos_sale_source' => $warehouse->pos_sale_source,
            'address' => $warehouse->address,
            'phone' => $warehouse->phone,
            'is_default' => (bool) $warehouse->is_default,
            'is_active' => (bool) $warehouse->is_active,
            'notes' => $warehouse->notes,
            'products_count' => $batches->where('quantity', '>', 0)->pluck('product_id')->unique()->count(),
            'total_quantity' => (float) $batches->sum('quantity'),
            'stock_value' => round($batches->sum(fn ($batch) => (float) $batch->quantity * (float) $batch->cost_price), 2),
            'expired_quantity' => (float) $batches
                ->filter(fn ($batch) => $batch->expiry_date && $batch->expiry_date->lt($today))
                ->sum('quantity'),
            'near_expiry_quantity' => (float) $batches
                ->filter(fn ($batch) => $batch->expiry_date
                    && $batch->expiry_date->gte($today)
                    && $batch->expiry_date->lte($today->copy()->addDays(30)))
                ->sum('quantity'),
        ];
    }

    protected function validateWarehouse(Request $request, int $restaurantId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('warehouses', 'code')
                    ->where(fn ($query) => $query->where('restaurant_id', $restaurantId))
                    ->ignore($ignoreId),
            ],
            'type' => 'nullable|in:store,warehouse,both',
            'allow_sales' => 'boolean',
            'pos_sale_source' => 'nullable|in:store,warehouse,both',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);
    }

    protected function authorizeWarehouse(Warehouse $warehouse): void
    {
        if ((int) $warehouse->restaurant_id !== (int) auth()->user()->restaurant_id) {
            abort(403, 'غير مسموح بالوصول إلى هذا المخزن');
        }
    }

    /**
     * @return array{enable_expiry_alerts: bool, enable_near_expiry_alerts: bool, enable_low_stock_alerts: bool, near_expiry_days: int, low_stock_threshold: int}
     */
    protected function alertSettings(int $restaurantId): array
    {
        $rows = Setting::where('restaurant_id', $restaurantId)
            ->where('group', 'alerts')
            ->pluck('value', 'key');

        $bool = fn (string $key, bool $default) => filter_var($rows[$key] ?? $default, FILTER_VALIDATE_BOOLEAN);

        return [
            'enable_expiry_alerts' => $bool('enable_expiry_alerts', true),
            'enable_near_expiry_alerts' => $bool('enable_near_expiry_alerts', true),
            'enable_low_stock_alerts' => $bool('enable_low_stock_alerts', true),
            'near_expiry_days' => max(1, (int) ($rows['near_expiry_days'] ?? 30)),
            'low_stock_threshold' => max(0, (int) ($rows['low_stock_threshold'] ?? 5)),
        ];
    }
}
