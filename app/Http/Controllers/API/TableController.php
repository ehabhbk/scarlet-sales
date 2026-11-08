<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Table;
use App\Models\Area;
use Illuminate\Http\Request;

class TableController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = Table::where('restaurant_id', $restaurantId)->with('area');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('area_id')) {
            $query->where('area_id', $request->area_id);
        }

        $paginated = $query->orderBy('name')->paginate($request->get('per_page', 15));

        // Auto-release occupied tables whose latest served order is older than
        // the configured number of minutes (0 disables the behaviour).
        $releaseMinutes = (int) \App\Models\Setting::where('restaurant_id', $restaurantId)
            ->where('group', 'tables')
            ->where('key', 'table_release_minutes')
            ->value('value');
        if ($releaseMinutes > 0) {
            foreach ($paginated as $table) {
                if ($table->status !== 'occupied') {
                    continue;
                }
                $latestServed = \App\Models\Order::where('table_id', $table->id)
                    ->where('status', 'served')
                    ->latest('updated_at')
                    ->first();
                if ($latestServed && $latestServed->updated_at
                    && $latestServed->updated_at->lt(now()->subMinutes($releaseMinutes))) {
                    $table->update(['status' => 'available']);
                    $table->status = 'available';
                }
            }
        }

        return response()->json($paginated);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'area_id' => 'nullable|exists:areas,id',
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:50|unique:tables,code',
            'capacity' => 'nullable|integer|min:1',
            'status' => 'sometimes|in:available,occupied,reserved,cleaning',
            'position' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['capacity'] = $validated['capacity'] ?? 4;
        $validated['status'] = $validated['status'] ?? 'available';
        $validated['is_active'] = $request->boolean('is_active', true);

        $table = Table::create($validated);

        return response()->json(['table' => $table->load('area'), 'message' => 'تم إضافة الطاولة'], 201);
    }

    public function show(Table $table)
    {
        if ($table->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['table' => $table->load('area', 'orders.items')]);
    }

    public function update(Request $request, Table $table)
    {
        if ($table->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:50|unique:tables,code,' . $table->id,
            'capacity' => 'sometimes|integer|min:1',
            'status' => 'sometimes|in:available,occupied,reserved,cleaning',
            'is_active' => 'sometimes|boolean',
        ]);
        $table->update($validated);
        return response()->json(['table' => $table->fresh(), 'message' => 'تم تحديث الطاولة']);
    }

    public function destroy(Table $table)
    {
        if ($table->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $table->delete();
        return response()->json(['message' => 'تم حذف الطاولة']);
    }
}

class AreaController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $areas = Area::where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        return response()->json(['areas' => $areas]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['is_active'] = $request->boolean('is_active', true);
        $area = Area::create($validated);
        return response()->json(['area' => $area, 'message' => 'تم إضافة المنطقة'], 201);
    }

    public function show(Area $area)
    {
        if ($area->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['area' => $area->load('tables')]);
    }

    public function update(Request $request, Area $area)
    {
        if ($area->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);
        $area->update($validated);
        return response()->json(['area' => $area->fresh(), 'message' => 'تم تحديث المنطقة']);
    }

    public function destroy(Area $area)
    {
        if ($area->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $area->delete();
        return response()->json(['message' => 'تم حذف المنطقة']);
    }
}
