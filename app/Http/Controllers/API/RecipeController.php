<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $recipes = Recipe::where('restaurant_id', $restaurantId)
            ->with('product', 'ingredients.inventoryItem', 'ingredients.unit')
            ->orderByDesc('created_at')
            ->paginate(15);
        return response()->json($recipes);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'name' => 'required|string|max:255',
            'yield_qty' => 'nullable|numeric|min:0',
            'yield_unit_id' => 'nullable|exists:inventory_units,id',
            'instructions' => 'nullable|string',
            'ingredients' => 'required|array|min:1',
            'ingredients.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'ingredients.*.unit_id' => 'required|exists:inventory_units,id',
            'ingredients.*.quantity' => 'required|numeric|min:0',
            'ingredients.*.notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $restaurantId = $request->user()->restaurant_id;
            $product = Product::find($validated['product_id']);
            if ($product->restaurant_id !== $restaurantId) {
                abort(403);
            }

            $totalCost = 0;
            foreach ($validated['ingredients'] as $ing) {
                $item = InventoryItem::find($ing['inventory_item_id']);
                $unitCost = $item->cost_per_unit;
                $totalCost += (float) $ing['quantity'] * (float) $unitCost;
            }

            $recipe = Recipe::create([
                'restaurant_id' => $restaurantId,
                'product_id' => $validated['product_id'],
                'name' => $validated['name'],
                'yield_qty' => $validated['yield_qty'] ?? 1,
                'yield_unit_id' => $validated['yield_unit_id'] ?? null,
                'total_cost' => $totalCost,
                'instructions' => $validated['instructions'] ?? null,
                'version' => 1,
                'is_active' => true,
            ]);

            foreach ($validated['ingredients'] as $ing) {
                $item = InventoryItem::find($ing['inventory_item_id']);
                RecipeIngredient::create([
                    'recipe_id' => $recipe->id,
                    'inventory_item_id' => $ing['inventory_item_id'],
                    'unit_id' => $ing['unit_id'],
                    'quantity' => $ing['quantity'],
                    'unit_cost' => $item->cost_per_unit,
                    'total_cost' => (float) $ing['quantity'] * (float) $item->cost_per_unit,
                    'notes' => $ing['notes'] ?? null,
                ]);
            }

            // Snapshot version
            $this->createVersion($recipe, 1, $request->user()->id, 'النسخة الأولى');

            return response()->json(['recipe' => $recipe->load('product', 'ingredients.inventoryItem'), 'message' => 'تم إنشاء الوصفة'], 201);
        });
    }

    public function show(Recipe $recipe)
    {
        if ($recipe->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['recipe' => $recipe->load('product', 'ingredients.inventoryItem.unit', 'versions')]);
    }

    public function update(Request $request, Recipe $recipe)
    {
        if ($recipe->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'yield_qty' => 'sometimes|numeric|min:0',
            'instructions' => 'sometimes|string',
            'is_active' => 'sometimes|boolean',
        ]);
        $recipe->update($validated);
        return response()->json(['recipe' => $recipe->fresh(), 'message' => 'تم تحديث الوصفة']);
    }

    public function destroy(Recipe $recipe)
    {
        if ($recipe->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $recipe->delete();
        return response()->json(['message' => 'تم حذف الوصفة']);
    }

    protected function createVersion(Recipe $recipe, $version, $userId, $note)
    {
        $snapshot = $recipe->ingredients->map(function ($ing) {
            return [
                'inventory_item_id' => $ing->inventory_item_id,
                'quantity' => (float) $ing->quantity,
                'unit_cost' => (float) $ing->unit_cost,
                'total_cost' => (float) $ing->total_cost,
            ];
        });
        \App\Models\RecipeVersion::create([
            'recipe_id' => $recipe->id,
            'version' => $version,
            'snapshot' => $snapshot,
            'total_cost' => $recipe->total_cost,
            'changed_by' => $userId,
            'change_note' => $note,
        ]);
    }
}
