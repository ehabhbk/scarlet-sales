<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'restaurant_id',
        'name',
        'phone',
        'email',
        'address',
        'birth_date',
        'customer_type',
        'loyalty_points',
        'total_spent',
        'total_orders',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'loyalty_points' => 'integer',
        'total_spent' => 'decimal:4',
        'total_orders' => 'integer',
        'is_active' => 'boolean',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'restaurant_id',
        'shift_id',
        'user_id',
        'employee_id',
        'name',
        'job_title',
        'phone',
        'email',
        'hire_date',
        'salary',
        'pay_type',
        'pay_rate',
        'pay_account_id',
        'pay_day',
        'pay_auto',
        'status',
        'avatar',
        'notes',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'salary' => 'decimal:4',
        'pay_rate' => 'decimal:4',
        'pay_day' => 'integer',
        'pay_auto' => 'boolean',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'pay_account_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }
}

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'work_date',
        'check_in',
        'check_out',
        'work_hours',
        'status',
        'notes',
    ];

    protected $casts = [
        'work_date' => 'date',
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'work_hours' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

class Shift extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'restaurant_id',
        'shift_group_id',
        'user_id',
        'employee_id',
        'name',
        'shift_date',
        'start_time',
        'end_time',
        'status',
        'opening_cash',
        'expected_cash',
        'actual_cash',
        'difference',
        'wages_total',
        'closed_at',
        'notes',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'opening_cash' => 'decimal:4',
        'expected_cash' => 'decimal:4',
        'actual_cash' => 'decimal:4',
        'difference' => 'decimal:4',
        'wages_total' => 'decimal:4',
        'closed_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'shift_employee_payments')
            ->withPivot('payment_method', 'paid_at', 'paid_amount', 'journal_entry_id', 'paid_by')
            ->withTimestamps();
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ShiftGroup::class, 'shift_group_id');
    }

    public function cashierBalances()
    {
        return $this->hasMany(ShiftCashierBalance::class);
    }

    /**
     * Wages posted by closed shifts inside a date range.
     *
     * Wages are consolidated into the shift-closing journal entry, so the
     * amount stored on the closed shift is the recognised expense for the
     * period. Open shifts are excluded because they have not been posted yet.
     *
     * @return array{total:float,count:int}
     */
    public static function wagesSummaryBetween(int $restaurantId, $from, $to): array
    {
        $fromDate = $from instanceof \DateTimeInterface ? $from->format('Y-m-d') : (string) $from;
        $toDate = $to instanceof \DateTimeInterface ? $to->format('Y-m-d') : (string) $to;

        $query = static::query()
            ->where('restaurant_id', $restaurantId)
            ->where('status', 'closed')
            ->whereDate('shift_date', '>=', $fromDate)
            ->whereDate('shift_date', '<=', $toDate);

        return [
            'total' => round((float) (clone $query)->sum('wages_total'), 4),
            'count' => (int) (clone $query)->where('wages_total', '>', 0)->count(),
        ];
    }
}
