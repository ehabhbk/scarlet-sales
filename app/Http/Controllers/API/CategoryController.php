<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $categories = Category::where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['categories' => $categories]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|exists:categories,id',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:categories,slug',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'color' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ]);

        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['slug'] = $validated['slug'] ?? \Illuminate\Support\Str::slug($validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $category = Category::create($validated);

        return response()->json(['category' => $category, 'message' => 'تم إضافة التصنيف بنجاح'], 201);
    }

    public function show(Category $category)
    {
        if ($category->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['category' => $category->load('children', 'parent')]);
    }

    public function update(Request $request, Category $category)
    {
        if ($category->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:categories,slug,' . $category->id,
            'is_active' => 'sometimes|boolean',
        ]);
        $category->update($validated);
        return response()->json(['category' => $category->fresh(), 'message' => 'تم تحديث التصنيف']);
    }

    public function destroy(Category $category)
    {
        if ($category->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $category->delete();
        return response()->json(['message' => 'تم حذف التصنيف']);
    }

    public function tree(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $categories = Category::where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $byParent = [];
        foreach ($categories as $c) {
            $byParent[$c->parent_id ?? 0][] = $c;
        }

        $build = function ($parentId = 0) use (&$build, &$byParent) {
            $result = [];
            foreach (($byParent[$parentId] ?? []) as $c) {
                $result[] = [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'color' => $c->color,
                    'children' => $build($c->id),
                ];
            }
            return $result;
        };

        return response()->json(['tree' => $build()]);
    }
}
