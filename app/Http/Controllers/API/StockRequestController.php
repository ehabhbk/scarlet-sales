<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\StockRequest;
use App\Models\Warehouse;
use App\Models\Product;
use App\Http\Services\StockService;
use Illuminate\Http\Request;

class StockRequestController extends Controller
{
    public function __construct(protected StockService $stock)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $restaurantId = $user->restaurant_id;
        $query = StockRequest::where('restaurant_id', $restaurantId)
            ->with(['fromWarehouse:id,name,type', 'toWarehouse:id,name,type', 'product:id,name,sku,barcode', 'requester:id,name', 'approver:id,name', 'receiver:id,name'])
            ->orderByDesc('created_at');

        // Users with explicit assignments only see requests touching their
        // warehouses/stores. Admins and unassigned users stay unrestricted.
        $assignedIds = $this->assignedWarehouseIds($user);
        if ($assignedIds !== null) {
            $query->where(function ($q) use ($assignedIds) {
                $q->whereIn('from_warehouse_id', $assignedIds)
                    ->orWhereIn('to_warehouse_id', $assignedIds);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        $validated = $request->validate([
            'from_warehouse_id' => 'required|integer',
            'to_warehouse_id' => 'required|integer|different:from_warehouse_id',
            'product_id' => 'required|integer',
            'quantity' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string',
        ]);

        $from = Warehouse::where('restaurant_id', $restaurantId)->findOrFail($validated['from_warehouse_id']);
        $to = Warehouse::where('restaurant_id', $restaurantId)->findOrFail($validated['to_warehouse_id']);
        $product = Product::where('restaurant_id', $restaurantId)->findOrFail($validated['product_id']);

        // User must be allowed to request stock from the source warehouse
        if (! $this->canRequestFrom($request->user(), (int) $from->id)) {
            abort(403, 'غير مسموح لك الطلب من هذا المخزن.');
        }
        if (! $this->canAccessWarehouse($request->user(), (int) $to->id)) {
            abort(403, 'غير مسموح لك استلام مخزون لهذا الموقع.');
        }

        $sr = StockRequest::create([
            'restaurant_id' => $restaurantId,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'product_id' => $product->id,
            'requested_quantity' => $validated['quantity'],
            'status' => 'pending',
            'requested_by' => $request->user()->id,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json(['data' => $sr->load('fromWarehouse', 'toWarehouse', 'product', 'requester')], 201);
    }

    public function approve(Request $request, StockRequest $stockRequest)
    {
        $this->authorizeRestaurant($stockRequest, $request);

        if (! $this->canApproveFor($request->user(), (int) $stockRequest->from_warehouse_id)) {
            abort(403, 'غير مسموح لك اعتماد طلبات هذا المخزن.');
        }

        if ($stockRequest->status !== 'pending') {
            return response()->json(['message' => 'الطلب ليس في حالة معلقة'], 422);
        }

        $approvedQty = (float) ($request->input('approved_quantity', $stockRequest->requested_quantity));
        if ($approvedQty <= 0) {
            return response()->json(['message' => 'الكمية المعتمدة يجب أن تكون أكبر من صفر'], 422);
        }

        $stockRequest->update([
            'status' => 'approved',
            'approved_quantity' => $approvedQty,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json(['data' => $stockRequest->fresh(['fromWarehouse', 'toWarehouse', 'product', 'requester', 'approver'])]);
    }

    public function receive(Request $request, StockRequest $stockRequest)
    {
        $this->authorizeRestaurant($stockRequest, $request);

        $user = $request->user();
        $canReceive = $this->canAccessWarehouse($user, (int) $stockRequest->to_warehouse_id)
            || $this->canApproveFor($user, (int) $stockRequest->from_warehouse_id);
        if (! $canReceive) {
            abort(403, 'غير مسموح لك استلام هذا الطلب.');
        }

        if ($stockRequest->status !== 'approved') {
            return response()->json(['message' => 'الطلب ليس في حالة معتمدة'], 422);
        }

        $product = Product::findOrFail($stockRequest->product_id);

        // Transfer stock from warehouse to POS upon receipt confirmation
        $this->stock->transfer(
            (int) $stockRequest->restaurant_id,
            (int) $stockRequest->from_warehouse_id,
            (int) $stockRequest->to_warehouse_id,
            $product,
            (float) $stockRequest->approved_quantity,
            (int) $request->user()->id,
            'stock_request_receive'
        );

        $stockRequest->update([
            'status' => 'completed',
            'received_by' => $request->user()->id,
            'received_at' => now(),
        ]);

        return response()->json(['data' => $stockRequest->fresh(['fromWarehouse', 'toWarehouse', 'product', 'requester', 'approver', 'receiver'])]);
    }

    public function reject(Request $request, StockRequest $stockRequest)
    {
        $this->authorizeRestaurant($stockRequest, $request);

        if (! $this->canApproveFor($request->user(), (int) $stockRequest->from_warehouse_id)) {
            abort(403, 'غير مسموح لك رفض طلبات هذا المخزن.');
        }

        if ($stockRequest->status !== 'pending') {
            return response()->json(['message' => 'الطلب ليس في حالة معلقة'], 422);
        }

        $stockRequest->update([
            'status' => 'rejected',
            'rejected_reason' => $request->input('reason'),
            'approved_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $stockRequest->fresh(['fromWarehouse', 'toWarehouse', 'product', 'requester', 'approver'])]);
    }

    protected function authorizeRestaurant(StockRequest $sr, Request $request): void
    {
        if ((int) $sr->restaurant_id !== (int) $request->user()->restaurant_id) {
            abort(403);
        }
    }

    /**
     * Warehouse ids the user is explicitly assigned to, or null when the user
     * is unrestricted (admin or no assignments at all).
     *
     * @return array<int>|null
     */
    protected function assignedWarehouseIds($user): ?array
    {
        if ($user->hasAnyRole(['admin', 'Super Admin', 'Admin'], 'sanctum')) {
            return null;
        }

        $ids = $user->warehouses()->pluck('warehouses.id')->map(fn ($id) => (int) $id)->all();

        return count($ids) > 0 ? $ids : null;
    }

    protected function canRequestFrom($user, int $warehouseId): bool
    {
        $assignedIds = $this->assignedWarehouseIds($user);
        if ($assignedIds === null) {
            return true;
        }
        if (! in_array($warehouseId, $assignedIds, true)) {
            return false;
        }

        return (bool) $user->warehouses()
            ->where('warehouses.id', $warehouseId)
            ->wherePivot('can_request_stock', true)
            ->exists();
    }

    protected function canApproveFor($user, int $warehouseId): bool
    {
        $assignedIds = $this->assignedWarehouseIds($user);
        if ($assignedIds === null) {
            return true;
        }
        if (! in_array($warehouseId, $assignedIds, true)) {
            return false;
        }

        return (bool) $user->warehouses()
            ->where('warehouses.id', $warehouseId)
            ->wherePivot('can_approve_stock', true)
            ->exists();
    }

    protected function canAccessWarehouse($user, int $warehouseId): bool
    {
        $assignedIds = $this->assignedWarehouseIds($user);

        return $assignedIds === null || in_array($warehouseId, $assignedIds, true);
    }
}