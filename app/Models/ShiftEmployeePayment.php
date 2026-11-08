<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftEmployeePayment extends Model
{
    protected $fillable = [
        'shift_id',
        'employee_id',
        'payment_method',
        'paid_at',
        'paid_amount',
        'journal_entry_id',
        'paid_by',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'paid_amount' => 'decimal:2',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
"