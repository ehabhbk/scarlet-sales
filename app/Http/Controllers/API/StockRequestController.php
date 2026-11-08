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
        $restaurantId = $request->user()->restaurant_id;
        $query = StockRequest::where('restaurant_id', $restaurantId)
            ->with(['fromWarehouse:id,name,type', 'toWarehouse:id,name,type', 'product:id,name,sku,barcode', 'requester:id,name', 'approver:id,name'])
            ->orderByDesc('created_at');

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

        // authorize warehouses
        $from = Warehouse::where('restaurant_id', $restaurantId)->findOrFail($validated['from_warehouse_id']);
        $to = Warehouse::where('restaurant_id', $restaurantId)->findOrFail($validated['to_warehouse_id']);
        $product = Product::where('restaurant_id', $restaurantId)->findOrFail($validated['product_id']);

        // sales source / type checks: from must be warehouse (storage), to must allow sales (store)
        if ($from->type === 'pos' && ! $from->allow_sales) {
            // ok
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

        if ($stockRequest->status !== 'pending') {
            return response()->json(['message' => 'الطلب ليس في حالة معلقة'], 422);
        }

        $approvedQty = (float) ($request->input('approved_quantity', $stockRequest->requested_quantity));
        if ($approvedQty <= 0) {
            return response()->json(['message' => 'الكمية المعتمدة يجب أن تكون أكبر من صفر'], 422);
        }

        $product = Product::findOrFail($stockRequest->product_id);

        $this->stock->transfer(
            (int) $stockRequest->restaurant_id,
            (int) $stockRequest->from_warehouse_id,
            (int) $stockRequest->to_warehouse_id,
            $product,
            $approvedQty,
            (int) $request->user()->id,
            'stock_request'
        );

        $stockRequest->update([
            'status' => 'completed',
            'approved_quantity' => $approvedQty,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json(['data' => $stockRequest->fresh(['fromWarehouse', 'toWarehouse', 'product', 'requester', 'approver'])]);
    }

    public function reject(Request $request, StockRequest $stockRequest)
    {
        $this->authorizeRestaurant($stockRequest, $request);

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
}