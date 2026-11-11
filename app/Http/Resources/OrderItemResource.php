<?php

namespace App\Http\Resources;

use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'size_id' => $this->size_id,
            'product' => $this->whenLoaded('product', fn() => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'image' => PublicMediaUrl::make($this->product->image),
            ] : null),
            'size' => $this->whenLoaded('size', fn() => $this->size ? [
                'id' => $this->size->id,
                'name' => $this->size->name,
            ] : null),
            'quantity' => (int) $this->quantity,
            'unit_type' => $this->unit_type,
            'unit_name' => $this->unit_name,
            'conversion_factor' => (float) $this->conversion_factor,
            'base_quantity' => (float) $this->base_quantity,
            'unit_price' => (float) $this->unit_price,
            'discount_amount' => (float) $this->discount_amount,
            'tax_amount' => (float) $this->tax_amount,
            'total_price' => (float) $this->total_price,
            'notes' => $this->notes,
            'kitchen_status' => $this->kitchen_status,
            'modifiers' => $this->whenLoaded('modifiers', fn() => $this->modifiers->map(fn($m) => [
                'id' => $m->id,
                'modifier_id' => $m->modifier_id,
                'modifier_option_id' => $m->modifier_option_id,
                'modifier' => $m->modifier?->name,
                'option' => $m->modifierOption?->name,
                'price_modifier' => (float) $m->price_modifier,
            ])),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
