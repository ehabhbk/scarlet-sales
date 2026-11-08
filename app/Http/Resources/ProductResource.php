<?php

namespace App\Http\Resources;

use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'description' => $this->description,
            'image' => PublicMediaUrl::make($this->image),
            'sale_price' => (float) $this->sale_price,
            'cost_price' => (float) $this->cost_price,
            'tax_rate' => $this->tax_rate ? (float) $this->tax_rate : null,
            'track_inventory' => (bool) $this->track_inventory,
            'current_stock' => (int) $this->current_stock,
            'min_stock' => (int) $this->min_stock,
            'sale_unit' => $this->sale_unit,
            'purchase_unit' => $this->purchase_unit,
            'expiry_alert_days' => (int) $this->expiry_alert_days,
            'low_stock_threshold' => (int) $this->low_stock_threshold,
            'total_stock' => $this->whenLoaded('batches', fn () => (float) $this->batches->sum('quantity')),
            'expired_quantity' => $this->whenLoaded('batches', fn () => (float) $this->batches
                ->filter(fn ($batch) => $batch->expiry_date && $batch->expiry_date->lt(today()))
                ->sum('quantity')),
            'near_expiry_quantity' => $this->whenLoaded('batches', fn () => (float) $this->batches
                ->filter(fn ($batch) => $batch->expiry_date
                    && $batch->expiry_date->gte(today())
                    && $batch->expiry_date->lte(today()->addDays(30)))
                ->sum('quantity')),
            'nearest_expiry' => $this->whenLoaded('batches', fn () => $this->batches
                ->filter(fn ($batch) => $batch->expiry_date && (float) $batch->quantity > 0)
                ->sortBy(fn ($batch) => $batch->expiry_date->timestamp)
                ->first()?->expiry_date?->toDateString()),
            'low_stock' => $this->whenLoaded('batches', fn () => (bool) $this->track_inventory
                && (float) $this->batches->sum('quantity') <= (int) $this->min_stock),
            'batches' => $this->whenLoaded('batches', fn () => $this->batches->map(fn ($batch) => [
                'id' => $batch->id,
                'warehouse_id' => $batch->warehouse_id,
                'warehouse_name' => $batch->relationLoaded('warehouse') ? $batch->warehouse?->name : null,
                'batch_number' => $batch->batch_number,
                'quantity' => (float) $batch->quantity,
                'cost_price' => (float) $batch->cost_price,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'received_at' => $batch->received_at?->toISOString(),
            ])),
            'is_available' => (bool) $this->is_available,
            'is_featured' => (bool) $this->is_featured,
            'is_best_seller' => (bool) $this->is_best_seller,
            'sort_order' => (int) $this->sort_order,
            'sales_count' => (int) $this->sales_count,
            'category' => $this->whenLoaded('category', fn() => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'color' => $this->category->color,
            ] : null),
            'kitchen_station_id' => $this->kitchen_station_id,
            'kitchen_station' => $this->whenLoaded('kitchenStation', fn() => $this->kitchenStation ? [
                'id' => $this->kitchenStation->id,
                'name' => $this->kitchenStation->display_name ?: $this->kitchenStation->name,
                'color' => $this->kitchenStation->color,
            ] : null),
            'sizes' => $this->whenLoaded('sizes', fn() => $this->sizes->map(fn($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'price_modifier' => (float) $s->price_modifier,
                'is_default' => (bool) $s->is_default,
            ])),
            'modifiers' => $this->whenLoaded('modifiers', fn() => $this->modifiers->map(fn($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'is_required' => (bool) $m->is_required,
                'min_selection' => (int) $m->min_selection,
                'max_selection' => (int) $m->max_selection,
                'options' => $m->options->map(fn($o) => [
                    'id' => $o->id,
                    'name' => $o->name,
                    'price_modifier' => (float) $o->price_modifier,
                    'is_default' => (bool) $o->is_default,
                ]),
            ])),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
