<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductSize;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductSizeController extends Controller
{
    public function store(Request $request, Product $product)
    {
        if ($product->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'price_modifier' => 'nullable|numeric',
            'sort_order' => 'nullable|integer|min:0',
            'is_default' => 'boolean',
        ]);
        $validated['price_modifier'] = $validated['price_modifier'] ?? 0;
        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['is_default'] = $request->boolean('is_default', false);
        $size = ProductSize::create(array_merge($validated, ['product_id' => $product->id]));
        return response()->json(['size' => $size, 'message' => 'تم إضافة البعد'], 201);
    }

    public function update(Request $request, Product $product, ProductSize $size)
    {
        if ($size->product_id !== $product->id || $product->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'price_modifier' => 'sometimes|numeric',
            'sort_order' => 'sometimes|integer|min:0',
            'is_default' => 'sometimes|boolean',
        ]);
        $size->update($validated);
        return response()->json(['size' => $size->fresh(), 'message' => 'تم تحديث البعد']);
    }

    public function destroy(Product $product, ProductSize $size)
    {
        if ($size->product_id !== $product->id || $product->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $size->delete();
        return response()->json(['message' => 'تم حذف البعد']);
    }
}

class ProductModifierController extends Controller
{
    public function store(Request $request, Product $product)
    {
        if ($product->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_required' => 'boolean',
            'min_selection' => 'nullable|integer|min:0',
            'max_selection' => 'nullable|integer|min:1',
            'sort_order' => 'nullable|integer|min:0',
            'options' => 'required|array|min:1',
            'options.*.name' => 'required|string|max:255',
            'options.*.price_modifier' => 'nullable|numeric',
            'options.*.sort_order' => 'nullable|integer|min:0',
            'options.*.is_default' => 'boolean',
        ]);

        $modifier = \App\Models\ProductModifier::create(array_merge($validated, [
            'restaurant_id' => $product->restaurant_id,
            'product_id' => $product->id,
            'is_required' => $request->boolean('is_required', false),
            'min_selection' => $validated['min_selection'] ?? 0,
            'max_selection' => $validated['max_selection'] ?? 1,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => true,
        ]));

        foreach ($validated['options'] as $i => $opt) {
            \App\Models\ModifierOption::create([
                'modifier_id' => $modifier->id,
                'name' => $opt['name'],
                'price_modifier' => $opt['price_modifier'] ?? 0,
                'sort_order' => $opt['sort_order'] ?? $i,
                'is_default' => $opt['is_default'] ?? false,
            ]);
        }

        return response()->json(['modifier' => $modifier->load('options'), 'message' => 'تم إضافة الإضافة'], 201);
    }

    public function update(Request $request, Product $product, \App\Models\ProductModifier $modifier)
    {
        if ($modifier->product_id !== $product->id || $product->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'is_required' => 'sometimes|boolean',
        ]);
        $modifier->update($validated);
        return response()->json(['modifier' => $modifier->fresh()->load('options'), 'message' => 'تم تحديث الإضافة']);
    }

    public function destroy(Product $product, \App\Models\ProductModifier $modifier)
    {
        if ($modifier->product_id !== $product->id || $product->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $modifier->delete();
        return response()->json(['message' => 'تم حذف الإضافة']);
    }
}

class InventoryUnitController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $units = \App\Models\InventoryUnit::where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        return response()->json(['units' => $units]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'abbreviation' => 'required|string|max:20',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['is_active'] = $request->boolean('is_active', true);
        $unit = \App\Models\InventoryUnit::create($validated);
        return response()->json(['unit' => $unit, 'message' => 'تم إضافة وحدة القياس'], 201);
    }

    public function update(Request $request, \App\Models\InventoryUnit $inventoryUnit)
    {
        if ($inventoryUnit->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'abbreviation' => 'sometimes|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);
        $inventoryUnit->update($validated);
        return response()->json(['unit' => $inventoryUnit->fresh(), 'message' => 'تم تحديث الوحدة']);
    }

    public function destroy(\App\Models\InventoryUnit $inventoryUnit)
    {
        if ($inventoryUnit->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $inventoryUnit->delete();
        return response()->json(['message' => 'تم حذف الوحدة']);
    }
}
