<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftGroup extends Model
{
    protected $fillable = [
        'restaurant_id',
        'opened_by',
        'name',
        'shift_date',
        'status',
    ];

    protected $casts = [
        'shift_date' => 'date',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'shift_group_employees')
            ->withPivot('payment_method', 'paid_at', 'paid_amount', 'journal_entry_id', 'paid_by')
            ->withTimestamps();
    }
}
