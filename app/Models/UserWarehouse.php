<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWarehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'warehouse_id',
        'can_sell',
        'can_request_stock',
        'can_approve_stock',
    ];

    protected $casts = [
        'can_sell' => 'boolean',
        'can_request_stock' => 'boolean',
        'can_approve_stock' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
