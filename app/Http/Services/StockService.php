<?php

namespace App\Http\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductStockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockService
{
    /**
     * Resolve the warehouse that should fulfil a sale.
     */
    public function resolveWarehouseId(?int $warehouseId, int $restaurantId): ?int
    {
        if ($warehouseId) {
            $valid = Warehouse::where('restaurant_id', $restaurantId)
                ->whereKey($warehouseId)
                ->where('is_active', true)
                ->exists();

            if (! $valid) {
                throw ValidationException::withMessages([
                    'warehouse_id' => ['المخزن المحدد غير صالح أو غير مفعّل.'],
                ]);
            }

            return $warehouseId;
        }

        return Warehouse::where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('id');
    }

    public function availableStock(Product $product, ?int $warehouseId): float
    {
        $query = ProductBatch::where('product_id', $product->id)
            ->where('quantity', '>', 0);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        return (float) $query->sum('quantity');
    }

    public function assertAvailable(Product $product, ?int $warehouseId, float $quantity): void
    {
        if (! $product->track_inventory) {
            return;
        }

        $available = $this->availableStock($product, $warehouseId);

        if ($available + 0.0001 < $quantity) {
            throw ValidationException::withMessages([
                'items' => [
                    "الكمية المتوفرة من \"{$product->name}\" هي {$available} فقط، والمطلوب {$quantity}.",
                ],
            ]);
        }
    }

    /**
     * Add a batch (or merge into an existing batch) of stock.
     */
    public function stockIn(
        int $restaurantId,
        int $warehouseId,
        int $productId,
        float $quantity,
        array $data,
        ?int $userId = null,
        string $referenceType = 'manual',
        ?int $referenceId = null
    ): ProductBatch {
        $quantity = round($quantity, 3);

        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['الكمية يجب أن تكون أكبر من صفر.']]);
        }

        $batchNumber = $data['batch_number'] ?? null;
        $expiryDate = $data['expiry_date'] ?? null;
        $costPrice = (float) ($data['cost_price'] ?? 0);

        $batchQuery = ProductBatch::where('restaurant_id', $restaurantId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId);

        $batch = null;
        if ($batchNumber) {
            // An explicit batch number identifies the lot on its own.
            $batch = (clone $batchQuery)
                ->where('batch_number', $batchNumber)
                ->first();
        } else {
            // Without a batch number, the same expiry date is the same lot:
            // matching expiry increments quantity, a different expiry creates
            // an independent batch row.
            $batch = (clone $batchQuery)
                ->when(
                    $expiryDate,
                    fn ($query) => $query->whereDate('expiry_date', $expiryDate),
                    fn ($query) => $query->whereNull('expiry_date')
                )
                ->first();
        }

        if ($batch) {
            $batch->quantity = (float) $batch->quantity + $quantity;
            if ($costPrice > 0) {
                $batch->cost_price = $costPrice;
            }
            if ($expiryDate) {
                $batch->expiry_date = $expiryDate;
            }
            $batch->save();
        } else {
            $batch = ProductBatch::create([
                'restaurant_id' => $restaurantId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'supplier_id' => $data['supplier_id'] ?? null,
                'batch_number' => $batchNumber,
                'quantity' => $quantity,
                'cost_price' => $costPrice,
                'expiry_date' => $expiryDate,
                'received_at' => $data['received_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);
        }

        $this->recordMovement([
            'restaurant_id' => $restaurantId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'product_batch_id' => $batch->id,
            'user_id' => $userId,
            'type' => 'in',
            'quantity' => $quantity,
            'cost_price' => $costPrice ?: (float) $batch->cost_price,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => $data['notes'] ?? 'إدخال مخزون',
        ]);

        $this->syncProductStock($productId);

        return $batch->fresh();
    }

    /**
     * Deduct stock using First-Expired-First-Out.
     */
    public function deductFefo(
        int $restaurantId,
        ?int $warehouseId,
        Product $product,
        float $quantity,
        ?int $userId = null,
        string $referenceType = 'order',
        ?int $referenceId = null
    ): void {
        if (! $product->track_inventory) {
            return;
        }

        $quantity = round($quantity, 3);
        if ($quantity <= 0) {
            return;
        }

        $query = ProductBatch::where('restaurant_id', $restaurantId)
            ->where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->lockForUpdate();

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        // Non-expiring batches go last, then earliest expiry first.
        $batches = $query
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get();

        $remaining = $quantity;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((float) $batch->quantity, $remaining);
            if ($take <= 0) {
                continue;
            }

            $batch->decrement('quantity', $take);

            $this->recordMovement([
                'restaurant_id' => $restaurantId,
                'warehouse_id' => $batch->warehouse_id,
                'product_id' => $batch->product_id,
                'product_batch_id' => $batch->id,
                'user_id' => $userId,
                'type' => 'out',
                'quantity' => $take,
                'cost_price' => (float) $batch->cost_price,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => 'صرف مبيعات',
            ]);

            $remaining -= $take;
        }

        if ($remaining > 0.0001) {
            throw ValidationException::withMessages([
                'items' => ["الكمية المتوفرة من \"{$product->name}\" غير كافية. المتبقي غير المتوفر: {$remaining}."],
            ]);
        }

        $this->syncProductStock($product->id);
    }

    /**
     * Move stock between warehouses while preserving batch expiry/cost data.
     */
    public function transfer(
        int $restaurantId,
        int $fromWarehouseId,
        int $toWarehouseId,
        Product $product,
        float $quantity,
        ?int $userId = null,
        ?string $notes = null
    ): void {
        if ($fromWarehouseId === $toWarehouseId) {
            throw ValidationException::withMessages(['to_warehouse_id' => ['لا يمكن التحويل إلى نفس المخزن.']]);
        }

        $quantity = round($quantity, 3);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['الكمية يجب أن تكون أكبر من صفر.']]);
        }

        $batches = ProductBatch::where('restaurant_id', $restaurantId)
            ->where('warehouse_id', $fromWarehouseId)
            ->where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->lockForUpdate()
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get();

        $remaining = $quantity;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((float) $batch->quantity, $remaining);
            if ($take <= 0) {
                continue;
            }

            $batch->decrement('quantity', $take);

            $this->recordMovement([
                'restaurant_id' => $restaurantId,
                'warehouse_id' => $fromWarehouseId,
                'product_id' => $product->id,
                'product_batch_id' => $batch->id,
                'user_id' => $userId,
                'type' => 'transfer_out',
                'quantity' => $take,
                'cost_price' => (float) $batch->cost_price,
                'reference_type' => 'transfer',
                'notes' => $notes ?: 'تحويل بين المخازن',
            ]);

            $destination = ProductBatch::where('restaurant_id', $restaurantId)
                ->where('warehouse_id', $toWarehouseId)
                ->where('product_id', $product->id)
                ->where('batch_number', $batch->batch_number)
                ->when(
                    $batch->expiry_date,
                    fn ($q) => $q->whereDate('expiry_date', $batch->expiry_date->toDateString()),
                    fn ($q) => $q->whereNull('expiry_date')
                )
                ->first();

            if ($destination) {
                $destination->increment('quantity', $take);
            } else {
                $destination = ProductBatch::create([
                    'restaurant_id' => $restaurantId,
                    'warehouse_id' => $toWarehouseId,
                    'product_id' => $product->id,
                    'supplier_id' => $batch->supplier_id,
                    'batch_number' => $batch->batch_number,
                    'quantity' => $take,
                    'cost_price' => $batch->cost_price,
                    'expiry_date' => $batch->expiry_date,
                    'received_at' => now(),
                    'notes' => $notes ?: 'تحويل بين المخازن',
                ]);
            }

            $this->recordMovement([
                'restaurant_id' => $restaurantId,
                'warehouse_id' => $toWarehouseId,
                'product_id' => $product->id,
                'product_batch_id' => $destination->id,
                'user_id' => $userId,
                'type' => 'transfer_in',
                'quantity' => $take,
                'cost_price' => (float) $batch->cost_price,
                'reference_type' => 'transfer',
                'notes' => $notes ?: 'تحويل بين المخازن',
            ]);

            $remaining -= $take;
        }

        if ($remaining > 0.0001) {
            throw ValidationException::withMessages([
                'quantity' => ["الكمية المتوفرة من \"{$product->name}\" في المخزن المصدر غير كافية."],
            ]);
        }

        $this->syncProductStock($product->id);
    }

    /**
     * Set a batch quantity to an absolute value (stocktaking / correction).
     */
    public function adjustBatch(int $batchId, float $newQuantity, ?int $userId = null, ?string $notes = null): ProductBatch
    {
        $batch = ProductBatch::findOrFail($batchId);
        $newQuantity = max(0, round($newQuantity, 3));
        $delta = round($newQuantity - (float) $batch->quantity, 3);

        if (abs($delta) < 0.0001) {
            return $batch;
        }

        $batch->update(['quantity' => $newQuantity]);

        $this->recordMovement([
            'restaurant_id' => $batch->restaurant_id,
            'warehouse_id' => $batch->warehouse_id,
            'product_id' => $batch->product_id,
            'product_batch_id' => $batch->id,
            'user_id' => $userId,
            'type' => 'adjustment',
            'quantity' => $delta,
            'cost_price' => (float) $batch->cost_price,
            'reference_type' => 'adjustment',
            'notes' => $notes ?: 'تسوية مخزون',
        ]);

        $this->syncProductStock($batch->product_id);

        return $batch->fresh();
    }

    /**
     * Update a batch's editable details (quantity, cost, expiry, batch number).
     */
    public function updateBatch(int $batchId, array $data, ?int $userId = null): ProductBatch
    {
        $batch = ProductBatch::findOrFail($batchId);

        if (array_key_exists('quantity', $data) && $data['quantity'] !== null && $data['quantity'] !== '') {
            $batch = $this->adjustBatch(
                $batch->id,
                (float) $data['quantity'],
                $userId,
                $data['notes'] ?? 'تعديل كمية الدفعة'
            );
        }

        $attributes = [];
        if (array_key_exists('cost_price', $data) && $data['cost_price'] !== null && $data['cost_price'] !== '') {
            $attributes['cost_price'] = (float) $data['cost_price'];
        }
        if (array_key_exists('expiry_date', $data)) {
            $attributes['expiry_date'] = $data['expiry_date'] ?: null;
        }
        if (array_key_exists('batch_number', $data)) {
            $attributes['batch_number'] = $data['batch_number'] ?: null;
        }

        if ($attributes !== []) {
            $batch->update($attributes);
        }

        return $batch->fresh();
    }

    /**
     * Restore every outstanding sale deduction for an order (or a single product).
     */
    public function restoreOrderStock(int $orderId, ?int $productId = null, ?int $userId = null): void
    {
        $outs = ProductStockMovement::where('reference_type', 'order')
            ->where('reference_id', $orderId)
            ->where('type', 'out')
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->get();

        if ($outs->isEmpty()) {
            return;
        }

        $restores = ProductStockMovement::where('reference_type', 'order_restore')
            ->where('reference_id', $orderId)
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->get();

        $restoredByBatch = $restores->groupBy('product_batch_id')
            ->map(fn ($group) => (float) $group->sum('quantity'));

        foreach ($outs->groupBy('product_batch_id') as $batchId => $group) {
            if (! $batchId) {
                continue;
            }

            $net = round((float) $group->sum('quantity') - (float) ($restoredByBatch[$batchId] ?? 0), 3);
            if ($net <= 0) {
                continue;
            }

            $batch = ProductBatch::withTrashed()->find($batchId);
            if (! $batch) {
                continue;
            }

            if ($batch->trashed()) {
                $batch->restore();
            }

            $batch->increment('quantity', $net);

            $this->recordMovement([
                'restaurant_id' => $batch->restaurant_id,
                'warehouse_id' => $batch->warehouse_id,
                'product_id' => $batch->product_id,
                'product_batch_id' => $batch->id,
                'user_id' => $userId,
                'type' => 'in',
                'quantity' => $net,
                'cost_price' => (float) $batch->cost_price,
                'reference_type' => 'order_restore',
                'reference_id' => $orderId,
                'notes' => 'استعادة مخزون بعد إلغاء أو تعديل الطلب',
            ]);

            $this->syncProductStock($batch->product_id);
        }
    }

    public function syncProductStock(int $productId): void
    {
        $total = (float) ProductBatch::where('product_id', $productId)->sum('quantity');
        Product::whereKey($productId)->update(['current_stock' => (int) round($total)]);
    }

    protected function recordMovement(array $data): ProductStockMovement
    {
        return ProductStockMovement::create($data);
    }
}
