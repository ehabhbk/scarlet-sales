<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderKitchenStation;
use App\Models\KitchenStation;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class KitchenController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $restaurantId = $user->restaurant_id;
        $canViewAll = $this->canViewAllOrders($user);
        abort_unless(
            $canViewAll || ($user->hasPermissionTo('view_station_kitchen_orders', 'sanctum') && $user->kitchen_station_id),
            403
        );
        $stationId = $canViewAll ? null : $user->kitchen_station_id;

        $orders = Order::where('restaurant_id', $restaurantId)
            ->whereIn('status', ['pending', 'confirmed', 'preparing', 'ready'])
            ->withCount(['items as unready_kitchen_items_count' => fn ($query) => $query
                ->where(fn ($status) => $status
                    ->whereNull('kitchen_status')
                    ->orWhereNotIn('kitchen_status', ['ready', 'served']))])
            ->when($stationId, fn ($query) => $query->whereHas('items.kitchenStations', fn ($stations) => $stations->where('station_id', $stationId)))
            ->with([
                'items' => function ($query) use ($stationId) {
                    $query->with(['product', 'kitchenStations.station']);
                    if ($stationId) {
                        $query->whereHas('kitchenStations', fn ($stations) => $stations->where('station_id', $stationId));
                    }
                },
                'table',
            ])
            ->orderBy('created_at')
            ->get();

        $grouped = $orders->map(function ($order) {
            $stations = $order->items
                ->flatMap(fn ($item) => $item->kitchenStations)
                ->pluck('station')
                ->filter()
                ->unique('id')
                ->map(fn ($station) => [
                    'id' => $station->id,
                    'name' => $station->display_name ?: $station->name,
                    'color' => $station->color,
                ])
                ->values();

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'type' => $order->type,
                'table' => optional($order->table)->name,
                'status' => $order->status,
                'created_at' => $order->created_at,
                'can_mark_served' => (int) $order->unready_kitchen_items_count === 0,
                'stations' => $stations,
                'items' => $order->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product' => $item->product->name,
                        'quantity' => $item->quantity,
                        'kitchen_status' => $item->kitchen_status,
                        'notes' => $item->notes,
                        'stations' => $item->kitchenStations->map(function ($ks) {
                            return [
                                'station' => $ks->station->display_name ?: $ks->station->name,
                                'station_id' => $ks->station->id,
                                'station_color' => $ks->station->color,
                                'prep_time_minutes' => $ks->prep_time_minutes,
                                'started_at' => $ks->started_at,
                                'completed_at' => $ks->completed_at,
                            ];
                        }),
                    ];
                }),
            ];
        });

        return response()->json(['orders' => $grouped]);
    }

    public function stations(Request $request)
    {
        $user = $request->user();
        $stations = KitchenStation::where('restaurant_id', $user->restaurant_id)
            ->when(! $this->canViewAllOrders($user) && $user->kitchen_station_id, fn ($query) => $query->whereKey($user->kitchen_station_id))
            ->orderBy('sort_order')
            ->get();
        return response()->json(['stations' => $stations]);
    }

    public function stationsForAssignment(Request $request)
    {
        return response()->json([
            'stations' => KitchenStation::where('restaurant_id', $request->user()->restaurant_id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function storeStation(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['color'] = $validated['color'] ?? '#3b82f6';
        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['is_active'] = $request->boolean('is_active', true);
        $station = KitchenStation::create($validated);
        return response()->json(['station' => $station, 'message' => 'تم إضافة المحطة'], 201);
    }

    public function updateStation(Request $request, KitchenStation $station)
    {
        if ($station->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'color' => 'sometimes|string|max:20',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);
        $station->update($validated);
        return response()->json(['station' => $station->fresh(), 'message' => 'تم تحديث المحطة']);
    }

    public function destroyStation(KitchenStation $station)
    {
        if ($station->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $station->delete();
        return response()->json(['message' => 'تم حذف المحطة']);
    }

    public function assignStation(Request $request, OrderItem $orderItem)
    {
        if ($orderItem->order->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'station_id' => 'required|exists:kitchen_stations,id',
            'prep_time_minutes' => 'nullable|integer|min:1',
        ]);

        $station = KitchenStation::find($validated['station_id']);
        if ($station->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        abort_unless(
            $this->canViewAllOrders($request->user())
                || (int) $request->user()->kitchen_station_id === (int) $station->id,
            403
        );

        return DB::transaction(function () use ($orderItem, $validated) {
            OrderKitchenStation::updateOrCreate(
                ['order_item_id' => $orderItem->id, 'station_id' => $validated['station_id']],
                ['prep_time_minutes' => $validated['prep_time_minutes'] ?? null]
            );
            return response()->json(['message' => 'تم تكليف المحطة']);
        });
    }

    public function updateItemStatus(Request $request, OrderItem $orderItem)
    {
        if ($orderItem->order->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'kitchen_status' => 'required_without:status|in:pending,confirmed,preparing,ready,served,cancelled',
            'status' => 'required_without:kitchen_status|in:pending,confirmed,preparing,ready,served,cancelled',
            'station_id' => 'nullable|integer',
        ]);

        $status = $validated['kitchen_status'] ?? $validated['status'];
        $stationId = $validated['station_id'] ?? null;
        if (! $this->canViewAllOrders($request->user())) {
            abort_unless(
                $request->user()->hasPermissionTo('view_station_kitchen_orders', 'sanctum')
                    && $request->user()->kitchen_station_id
                    && (int) $request->user()->kitchen_station_id === (int) $stationId,
                403
            );
            abort_unless(
                OrderKitchenStation::where('order_item_id', $orderItem->id)
                    ->where('station_id', $stationId)
                    ->exists(),
                403
            );
        }

        if ($stationId !== null) {
            $stationBelongsToRestaurant = KitchenStation::where('restaurant_id', $request->user()->restaurant_id)
                ->whereKey($stationId)
                ->exists();
            if (!$stationBelongsToRestaurant) {
                abort(422, 'محطة المطبخ المحددة غير موجودة');
            }
        }

        return DB::transaction(function () use ($orderItem, $status, $stationId) {
            $orderItem->update(['kitchen_status' => $status]);

            if ($status === 'ready') {
                $stationQuery = OrderKitchenStation::where('order_item_id', $orderItem->id);
                if ($stationId !== null) {
                    $stationQuery->where('station_id', $stationId);
                }
                $stationQuery->update(['completed_at' => now()]);
            }

            return response()->json([
                'order_item' => $orderItem->fresh(),
                'message' => 'تم تحديث حالة التحضير',
            ]);
        });
    }

    public function startTimer(Request $request, OrderItem $orderItem)
    {
        if ($orderItem->order->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'station_id' => 'required|exists:kitchen_stations,id',
        ]);
        abort_unless(
            $this->canViewAllOrders($request->user())
                || (int) $request->user()->kitchen_station_id === (int) $validated['station_id'],
            403
        );
        OrderKitchenStation::updateOrCreate(
            ['order_item_id' => $orderItem->id, 'station_id' => $validated['station_id']],
            ['started_at' => now()]
        );
        return response()->json(['message' => 'بدأ المؤقت']);
    }

    public function completeTimer(Request $request, OrderItem $orderItem)
    {
        if ($orderItem->order->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'station_id' => 'required|exists:kitchen_stations,id',
        ]);
        abort_unless(
            $this->canViewAllOrders($request->user())
                || (int) $request->user()->kitchen_station_id === (int) $validated['station_id'],
            403
        );
        OrderKitchenStation::where('order_item_id', $orderItem->id)
            ->where('station_id', $validated['station_id'])
            ->update(['completed_at' => now()]);
        return response()->json(['message' => 'أكتمل التحضير']);
    }

    private function canViewAllOrders($user): bool
    {
        return $user->hasPermissionTo('view_all_kitchen_orders', 'sanctum')
            || ($user->hasPermissionTo('view_kitchen', 'sanctum')
                && ! $user->hasPermissionTo('view_station_kitchen_orders', 'sanctum'));
    }

    public function products(Request $request)
    {
        $user = $request->user();
        $restaurantId = $user->restaurant_id;
        $canViewAll = $this->canViewAllOrders($user);
        abort_unless(
            $canViewAll || ($user->hasPermissionTo('view_station_kitchen_orders', 'sanctum') && $user->kitchen_station_id)
                || $user->hasPermissionTo('update_kitchen_order', 'sanctum'),
            403
        );

        $stationId = $canViewAll ? $request->query('station_id') : $user->kitchen_station_id;

        $query = Product::where('restaurant_id', $restaurantId);
        if ($stationId) {
            $query->where('kitchen_station_id', $stationId);
        }

        return response()->json([
            'products' => $query->orderBy('name')->get(['id', 'name', 'image', 'sale_price', 'is_available', 'kitchen_station_id']),
        ]);
    }

    public function updateProductAvailability(Request $request, Product $product)
    {
        $user = $request->user();
        abort_unless($product->restaurant_id === $user->restaurant_id, 403);
        $canViewAll = $this->canViewAllOrders($user);
        if (! $canViewAll) {
            abort_unless($user->kitchen_station_id && (int) $product->kitchen_station_id === (int) $user->kitchen_station_id, 403);
        }
        abort_unless(
            $user->hasPermissionTo('manage_kitchen_products', 'sanctum'),
            403
        );

        $validated = $request->validate([
            'is_available' => 'required|boolean',
        ]);

        $product->update(['is_available' => $validated['is_available']]);

        return response()->json([
            'product' => $product->fresh(),
            'message' => $validated['is_available'] ? 'تم تفعيل المنتج' : 'تم تعطيل المنتج (نفذ)',
        ]);
    }
}
