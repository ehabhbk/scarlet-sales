<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'product_id',
        'requested_quantity',
        'approved_quantity',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'notes',
        'received_by',
        'received_at',
    ];

    protected $casts = [
        'requested_quantity' => 'decimal:3',
        'approved_quantity' => 'decimal:3',
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}