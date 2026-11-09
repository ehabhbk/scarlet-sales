<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use App\Http\Services\ProductService;
use App\Http\Services\StockService;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Resources\ProductResource;

class ProductController extends Controller
{
    protected ProductService $service;
    protected StockService $stock;

    public function __construct(ProductService $service, StockService $stock)
    {
        $this->service = $service;
        $this->stock = $stock;
    }

    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = Product::where('restaurant_id', $restaurantId);

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->has('is_available')) {
            $query->where('is_available', $request->boolean('is_available'));
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $perPage = min(max($request->integer('per_page', 15), 1), 200);
        $products = $query->with('category', 'sizes', 'modifiers.options', 'batches')
            ->orderBy('sort_order')
            ->paginate($perPage);

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request)
    {
        $validated = $request->validated();
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['slug'] = $validated['slug'] ?? \Illuminate\Support\Str::slug($validated['name']);
        $validated['track_inventory'] = $request->boolean('track_inventory', true);
        $validated['is_available'] = $request->boolean('is_available', true);
        if (isset($validated['warehouse_id'])) {
            $validated['default_warehouse_id'] = $validated['warehouse_id'];
        }
        if ($request->hasFile('image_file')) {
            $validated['image'] = $request->file('image_file')->store('products', 'public');
        }

        $product = $this->service->create($validated);

        $initialQuantity = (float) ($validated['initial_quantity'] ?? 0);
        if ($initialQuantity > 0) {
            $warehouseId = $this->stock->resolveWarehouseId(
                isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
                $request->user()->restaurant_id
            );

            if ($warehouseId) {
                $this->stock->stockIn(
                    (int) $request->user()->restaurant_id,
                    $warehouseId,
                    (int) $product->id,
                    $initialQuantity,
                    [
                        'cost_price' => $validated['cost_price'] ?? $product->cost_price,
                        'expiry_date' => $validated['expiry_date'] ?? null,
                        'batch_number' => $validated['batch_number'] ?? null,
                        'supplier_id' => $validated['supplier_id'] ?? null,
                    ],
                    (int) $request->user()->id,
                    'product_opening'
                );
                $product = $product->fresh();
            }
        }

        return (new ProductResource($product->load('category', 'sizes', 'modifiers.options', 'batches')))
            ->additional(['message' => 'تم إضافة المنتج بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Product $product)
    {
        $this->authorizeRestaurant($product);
        return new ProductResource($product->load('category', 'kitchenStation', 'sizes', 'modifiers.options', 'batches.warehouse'));
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeRestaurant($product);

        $validated = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'kitchen_station_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('kitchen_stations', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $request->user()->restaurant_id)
                    ->where('is_active', true)),
            ],
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:products,slug,' . $product->id,
            'sku' => 'sometimes|string|max:100|unique:products,sku,' . $product->id,
            'barcode' => 'sometimes|string|max:100|unique:products,barcode,' . $product->id,
            'sale_price' => 'sometimes|numeric|min:0',
            'cost_price' => 'sometimes|numeric|min:0',
            'is_available' => 'sometimes|boolean',
            'description' => 'sometimes|nullable|string',
            'tax_rate' => 'sometimes|nullable|numeric|min:0|max:1',
            'track_inventory' => 'sometimes|boolean',
            'current_stock' => 'sometimes|integer|min:0',
            'min_stock' => 'sometimes|integer|min:0',
            'sale_unit' => 'sometimes|nullable|string|max:50',
            'purchase_unit' => 'sometimes|nullable|string|max:50',
            'sub_unit' => 'sometimes|nullable|string|max:50',
            'conversion_factor' => 'sometimes|nullable|integer|min:1',
            'expiry_alert_days' => 'sometimes|nullable|integer|min:0',
            'low_stock_threshold' => 'sometimes|nullable|integer|min:0',
            'is_featured' => 'sometimes|boolean',
            'is_best_seller' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'image_file' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        if ($request->hasFile('image_file')) {
            $this->deleteStoredImage($product->image);
            $validated['image'] = $request->file('image_file')->store('products', 'public');
        }
        unset($validated['image_file']);
        $this->service->update($product, $validated);

        $updated = $product->fresh()->load('category', 'sizes', 'modifiers.options', 'batches');

        return response()->json([
            'product' => new ProductResource($updated),
            'message' => 'تم تحديث المنتج بنجاح',
        ]);
    }

    public function destroy(Product $product)
    {
        $this->authorizeRestaurant($product);
        $product->delete();
        return response()->json(['message' => 'تم حذف المنتج بنجاح']);
    }

    public function available(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $warehouseId = $this->stock->resolveWarehouseId(
            $request->filled('warehouse_id') ? $request->integer('warehouse_id') : null,
            (int) $restaurantId
        );

        $products = Product::where('restaurant_id', $restaurantId)
            ->where('is_available', true)
            ->with([
                'category', 'sizes', 'modifiers.options',
                'batches' => function ($query) use ($warehouseId) {
                    if ($warehouseId) {
                        $query->where('warehouse_id', $warehouseId);
                    }
                },
            ])
            ->orderBy('sort_order')
            ->get()
            ->map(function (Product $product) use ($warehouseId) {
                $stock = (float) $product->batches->sum('quantity');
                $product->image = PublicMediaUrl::make($product->image);
                $product->loadMissing('kitchenStation');

                $product->setAttribute('warehouse_id', $warehouseId);
                $product->setAttribute('stock', $stock);
                $product->setAttribute('in_stock', ! $product->track_inventory || $stock > 0);
                $product->setAttribute(
                    'nearest_expiry',
                    $product->batches
                        ->filter(fn ($batch) => $batch->expiry_date && (float) $batch->quantity > 0)
                        ->sortBy(fn ($batch) => $batch->expiry_date->timestamp)
                        ->first()?->expiry_date?->toDateString()
                );
                $product->unsetRelation('batches');

                return $product;
            });

        return response()->json([
            'warehouse_id' => $warehouseId,
            'products' => $products,
        ]);
    }

    public function sizes(Product $product)
    {
        $this->authorizeRestaurant($product);
        return response()->json(['sizes' => $product->sizes()->orderBy('sort_order')->get()]);
    }

    public function modifiers(Product $product)
    {
        $this->authorizeRestaurant($product);
        return response()->json(['modifiers' => $product->modifiers()->with('options')->get()]);
    }

    public function uploadImage(Request $request, Product $product)
    {
        $this->authorizeRestaurant($product);
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);
        if ($request->hasFile('image')) {
            $this->deleteStoredImage($product->image);
            $path = $request->file('image')->store('products', 'public');
            $product->update(['image' => $path]);
            return response()->json(['image' => PublicMediaUrl::make($path), 'message' => 'تم رفع الصورة']);
        }
        return response()->json(['message' => 'لم يتم رفع ملف'], 400);
    }

    protected function deleteStoredImage(?string $url): void
    {
        $path = PublicMediaUrl::storagePath($url);
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    protected function authorizeRestaurant($model)
    {
        if ($model->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403, 'غير مسموح بالوصول إلى هذا المورد');
        }
    }
}
