<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'restaurant_id',
        'table_id',
        'customer_id',
        'cashier_id',
        'waiter_id',
        'shift_id',
        'warehouse_id',
        'order_number',
        'type',
        'status',
        'payment_status',
        'payment_method',
        'payment_methods',
        'subtotal',
        'discount_amount',
        'discount_percent',
        'tax_amount',
        'total_amount',
        'amount_paid',
        'change_due',
        'notes',
        'customer_notes',
        'delivery_address',
        'delivery_phone',
        'delivery_fee',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'subtotal' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'discount_percent' => 'decimal:2',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'amount_paid' => 'decimal:4',
        'change_due' => 'decimal:4',
        'delivery_fee' => 'decimal:4',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'payment_methods' => 'json',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class)->orderBy('sort_order');
    }

    public function statusHistories()
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at', 'desc');
    }

    public function payments()
    {
        return $this->morphMany(Payment::class, 'paymentable');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }
}
