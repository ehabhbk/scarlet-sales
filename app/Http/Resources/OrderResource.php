<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shift_id' => $this->shift_id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
                'code' => $this->warehouse->code,
            ] : null),
            'order_number' => $this->order_number,
            'type' => $this->type,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'discount_percent' => (float) $this->discount_percent,
            'tax_amount' => (float) $this->tax_amount,
            'total_amount' => (float) $this->total_amount,
            'amount_paid' => (float) $this->amount_paid,
            'change_due' => (float) $this->change_due,
            'notes' => $this->notes,
            'customer_notes' => $this->customer_notes,
            'delivery_address' => $this->delivery_address,
            'delivery_phone' => $this->delivery_phone,
            'delivery_fee' => (float) $this->delivery_fee,
            'table' => $this->whenLoaded('table', fn() => $this->table ? [
                'id' => $this->table->id,
                'name' => $this->table->name,
                'code' => $this->table->code,
            ] : null),
            'customer' => $this->whenLoaded('customer', fn() => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ] : null),
            'cashier' => $this->whenLoaded('cashier', fn() => $this->cashier ? [
                'id' => $this->cashier->id,
                'name' => $this->cashier->name,
            ] : null),
            'waiter' => $this->whenLoaded('waiter', fn() => $this->waiter ? [
                'id' => $this->waiter->id,
                'name' => $this->waiter->name,
            ] : null),
            'items' => $this->whenLoaded('items', fn() => OrderItemResource::collection($this->items)),
            'payments' => $this->whenLoaded('payments', fn() => $this->payments->map(fn($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'reference' => $p->reference,
                'paid_at' => $p->paid_at?->toISOString(),
            ])),
            'completed_at' => $this->completed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
