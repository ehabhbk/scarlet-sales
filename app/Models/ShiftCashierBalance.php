<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftCashierBalance extends Model
{
    protected $fillable = [
        'restaurant_id',
        'shift_id',
        'user_id',
        'opening_cash',
        'opening_cash_set',
        'expected_cash',
        'actual_cash',
        'difference',
        'closed_at',
    ];

    protected $casts = [
        'opening_cash' => 'decimal:4',
        'opening_cash_set' => 'boolean',
        'expected_cash' => 'decimal:4',
        'actual_cash' => 'decimal:4',
        'difference' => 'decimal:4',
        'closed_at' => 'datetime',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
