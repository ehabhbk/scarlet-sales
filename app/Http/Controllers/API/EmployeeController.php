<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftCashierBalance;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Services\CashierAccountService;
use App\Services\JournalPostingService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $employees = Employee::where('restaurant_id', $restaurantId)
            ->with('user', 'payAccount')
            ->orderBy('name')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));

        return response()->json($employees);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('restaurant_id', $request->user()->restaurant_id),
            ],
            'employee_id' => 'required|string|max:50|unique:employees,employee_id',
            'name' => 'required|string|max:255',
            'job_title' => 'required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'hire_date' => 'required|date',
            'salary' => 'nullable|numeric|min:0',
            'pay_type' => 'sometimes|in:monthly,daily,percentage',
            'pay_rate' => 'nullable|numeric|min:0',
            'pay_account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $request->user()->restaurant_id)
                    ->where('type', 'expense')
                    ->where('is_active', true)),
            ],
            'pay_day' => 'nullable|integer|min:1|max:31',
            'pay_auto' => 'sometimes|boolean',
            'status' => 'sometimes|in:active,on_leave,terminated',
            'avatar' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['salary'] = $validated['salary'] ?? 0;
        $validated['pay_type'] = $validated['pay_type'] ?? 'monthly';
        $validated['pay_rate'] = $validated['pay_rate'] ?? 0;
        $validated['pay_auto'] = $request->boolean('pay_auto', true);
        $validated['status'] = $validated['status'] ?? 'active';
        $employee = DB::transaction(function () use ($validated) {
            $user = ! empty($validated['user_id'])
                ? User::where('restaurant_id', $validated['restaurant_id'])->whereKey($validated['user_id'])->firstOrFail()
                : null;
            $validated['pay_account_id'] = $validated['pay_account_id'] ?? $user?->pay_account_id;
            $employee = Employee::create($validated);
            if (! $employee->pay_account_id) {
                $parent = Account::firstOrCreate(
                    ['restaurant_id' => $employee->restaurant_id, 'code' => '5000'],
                    ['name' => 'رواتب وأجور', 'type' => 'expense', 'opening_balance' => 0, 'is_active' => true]
                );
                if ($parent->type !== 'expense' || ! $parent->is_active) {
                    throw ValidationException::withMessages([
                        'pay_account_id' => ['الحساب الافتراضي للأجور غير صالح أو غير نشط.'],
                    ]);
                }
                $typeName = match ($employee->pay_type) {
                    'daily' => 'يومية',
                    'percentage' => 'نسبة',
                    default => 'راتب',
                };
                $payAccount = Account::firstOrCreate(
                    [
                        'restaurant_id' => $employee->restaurant_id,
                        'code' => '52'.str_pad((string) $employee->id, 6, '0', STR_PAD_LEFT),
                    ],
                    [
                        'parent_id' => $parent->id,
                        'name' => 'مصروف '.$typeName.' '.$employee->name,
                        'type' => 'expense',
                        'opening_balance' => 0,
                        'is_active' => true,
                    ]
                );
                if ($payAccount->type !== 'expense' || ! $payAccount->is_active) {
                    throw ValidationException::withMessages([
                        'pay_account_id' => ['تعذر إنشاء حساب مصروف صالح للموظف.'],
                    ]);
                }
                $employee->update(['pay_account_id' => $payAccount->id]);
            }
            if ($employee->user_id) {
                User::whereKey($employee->user_id)->update(['pay_account_id' => $employee->pay_account_id]);
            }

            return $employee;
        });

        return response()->json(['employee' => $employee->load('user', 'payAccount'), 'message' => 'تم إضافة الموظف'], 201);
    }

    public function show(Employee $employee)
    {
        if ($employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        return response()->json(['employee' => $employee->load('user', 'payAccount', 'attendances')]);
    }

    public function update(Request $request, Employee $employee)
    {
        if ($employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'user_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('restaurant_id', $employee->restaurant_id),
            ],
            'job_title' => 'sometimes|string|max:100',
            'salary' => 'sometimes|numeric|min:0',
            'pay_type' => 'sometimes|in:monthly,daily,percentage',
            'pay_rate' => 'sometimes|numeric|min:0',
            'pay_account_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $employee->restaurant_id)
                    ->where('type', 'expense')
                    ->where('is_active', true)),
            ],
            'pay_day' => 'sometimes|nullable|integer|min:1|max:31',
            'pay_auto' => 'sometimes|boolean',
            'status' => 'sometimes|in:active,on_leave,terminated',
        ]);
        DB::transaction(function () use ($employee, $validated) {
            $employee->update($validated);
            if ($employee->user_id && $employee->pay_account_id) {
                User::where('restaurant_id', $employee->restaurant_id)
                    ->whereKey($employee->user_id)
                    ->update(['pay_account_id' => $employee->pay_account_id]);
            }
        });

        return response()->json(['employee' => $employee->fresh()->load('user', 'payAccount'), 'message' => 'تم تحديث الموظف']);
    }

    public function destroy(Employee $employee)
    {
        if ($employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $employee->delete();

        return response()->json(['message' => 'تم حذف الموظف']);
    }

    public function paySalary(Request $request, Employee $employee)
    {
        if ($employee->restaurant_id !== $request->user()->restaurant_id) {
            abort(403);
        }
        abort_unless($request->user()->hasPermissionTo('pay_employee_wages', 'sanctum'), 403);
        if ($employee->pay_type !== 'monthly') {
            return response()->json(['message' => 'هذا الموظف ليس على راتب شهري'], 400);
        }
        $restaurantId = $employee->restaurant_id;
        $validated = $request->validate([
            'payment_method' => 'required|string|max:50',
        ]);
        app(JournalPostingService::class)
            ->assertConfiguredPaymentMethod($employee->restaurant_id, $validated['payment_method']);
        $amount = (float) $employee->salary;
        if ($amount <= 0) {
            return response()->json(['message' => 'الراتب صفر'], 400);
        }
        $reference = 'SALARY-'.$employee->id.'-'.now()->format('Y-m');
        if (JournalEntry::where('restaurant_id', $restaurantId)->where('reference', $reference)->exists()) {
            return response()->json(['message' => 'تم تسجيل راتب هذا الموظف لهذا الشهر مسبقًا'], 409);
        }
        $payAccountId = $employee->pay_account_id ?: $employee->user?->pay_account_id;
        $expenseAccount = $payAccountId
            ? Account::where('id', $payAccountId)->where('restaurant_id', $restaurantId)->where('type', 'expense')->where('is_active', true)->first()
            : Account::firstOrCreate(
                ['restaurant_id' => $restaurantId, 'code' => '5000'],
                ['name' => 'رواتب وأجور', 'type' => 'expense', 'opening_balance' => 0, 'is_active' => true]
            );
        if (! $expenseAccount) {
            return response()->json(['message' => 'حساب غير صالح'], 400);
        }
        $entry = DB::transaction(function () use ($request, $employee, $restaurantId, $amount, $expenseAccount, $validated, $reference) {
            $cashAccount = app(JournalPostingService::class)
                ->assertConfiguredPaymentMethod($restaurantId, $validated['payment_method']);

            return app(JournalPostingService::class)->post(
                $restaurantId,
                'SAL-',
                $reference,
                now()->toDateString(),
                'راتب '.$employee->name,
                $request->user()->id,
                [
                    ['account_id' => $expenseAccount->id, 'debit' => $amount, 'description' => 'راتب شهري'],
                    ['account_id' => $cashAccount->id, 'credit' => $amount, 'description' => 'راتب شهري'],
                ]
            );
        });

        return response()->json(['message' => 'تم دفع الراتب وإنشاء قيد بنجاح', 'entry' => $entry->load('lines.account')]);
    }
}

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = Attendance::whereHas('employee', function ($q) use ($restaurantId) {
            $q->where('restaurant_id', $restaurantId);
        })->with('employee');

        if ($request->has('work_date')) {
            $query->where('work_date', $request->work_date);
        }
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        return response()->json($query->orderByDesc('work_date')->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'work_date' => 'required|date|unique:attendances,employee_id,NULL,id,work_date,'.$request->employee_id,
            'check_in' => 'nullable|date_format:H:i',
            'check_out' => 'nullable|date_format:H:i',
            'status' => 'sometimes|in:present,absent,late,half_day',
            'notes' => 'nullable|string',
        ]);

        $employee = Employee::find($validated['employee_id']);
        if ($employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        $validated['work_hours'] = 0;
        if ($validated['check_in'] && $validated['check_out']) {
            $start = Carbon::parse($validated['check_in']);
            $end = Carbon::parse($validated['check_out']);
            $validated['work_hours'] = (float) $start->diffInHours($end, true);
        }
        $validated['status'] = $validated['status'] ?? 'present';

        $attendance = Attendance::create($validated);

        return response()->json(['attendance' => $attendance->load('employee'), 'message' => 'تم تسجيل الحضور'], 201);
    }

    public function show(Attendance $attendance)
    {
        if ($attendance->employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        return response()->json(['attendance' => $attendance->load('employee')]);
    }

    public function update(Request $request, Attendance $attendance)
    {
        if ($attendance->employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'check_in' => 'sometimes|date_format:H:i',
            'check_out' => 'sometimes|date_format:H:i',
            'status' => 'sometimes|in:present,absent,late,half_day',
        ]);
        if ($request->has('check_in') && $request->has('check_out')) {
            $start = Carbon::parse($request->check_in);
            $end = Carbon::parse($request->check_out);
            $validated['work_hours'] = (float) $start->diffInHours($end, true);
        }
        $attendance->update($validated);

        return response()->json(['attendance' => $attendance->fresh(), 'message' => 'تم تحديث الحضور']);
    }

    public function destroy(Attendance $attendance)
    {
        if ($attendance->employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $attendance->delete();

        return response()->json(['message' => 'تم حذف سجل الحضور']);
    }
}

class ShiftWorkflowController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        abort_unless(
            $request->user()->hasPermissionTo('view_all_shifts', 'sanctum')
                || $request->user()->hasPermissionTo('close_shift', 'sanctum'),
            403
        );
        $query = Shift::where('restaurant_id', $restaurantId);
        if (! $request->user()->hasPermissionTo('view_all_shifts', 'sanctum')) {
            $query->where('user_id', $request->user()->id);
        }
        $shifts = $query
            ->with('user', 'employee', 'employees', 'group.employees')
            ->orderByDesc('shift_date')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));
        $shifts->getCollection()->each(function (Shift $shift): void {
            $hasOtherOpenShift = $shift->shift_group_id
                ? Shift::where('shift_group_id', $shift->shift_group_id)
                    ->where('status', 'open')
                    ->where('id', '!=', $shift->id)
                    ->exists()
                : false;
            if ($shift->group) {
                $shift->setRelation('employees', $shift->group->employees);
            }
            $shift->setAttribute('wage_disbursement_due', ! $hasOtherOpenShift);
        });

        return response()->json($shifts);
    }

    public function options(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userHasShiftPermission($user, 'open_shift'), 403);
        $canManageEmployees = $this->userHasShiftPermission($user, 'manage_shift_employees');
        $employees = $canManageEmployees
            ? Employee::where('restaurant_id', $user->restaurant_id)
                ->where('status', 'active')
                ->where('pay_auto', true)
                ->whereIn('pay_type', ['daily', 'percentage'])
                ->with('payAccount')
                ->orderBy('name')
                ->get()
            : collect();

        return response()->json([
            'employees' => $employees,
            'permissions' => [
                'manage_shift_employees' => $canManageEmployees,
                'pay_employee_wages' => $this->userHasShiftPermission($user, 'pay_employee_wages'),
            ],
        ]);
    }

    public function employeeWages(Request $request, Shift $shift)
    {
        abort_unless($shift->restaurant_id === $request->user()->restaurant_id, 403);
        abort_unless($request->user()->hasPermissionTo('pay_employee_wages', 'sanctum'), 403);
        abort_unless($shift->status === 'open', 422, 'لا يمكن دفع أجور وردية مغلقة.');

        $group = $shift->group;
        $employees = $this->employeesForWages($shift, $group);
        $revenue = $this->wageRevenue($shift, $group);
        $workDate = Carbon::parse($shift->shift_date)->toDateString();

        $wages = $employees
            ->where('restaurant_id', $shift->restaurant_id)
            ->where('status', 'active')
            ->where('pay_auto', true)
            ->whereIn('pay_type', ['daily', 'percentage'])
            ->map(function (Employee $employee) use ($revenue, $workDate, $shift, $group): array {
                \Log::info('employee pivot', ['id'=>$employee->id, 'paid_at'=>$employee->pivot->paid_at, 'payment_method'=>$employee->pivot->payment_method]);
                $amount = $employee->pay_type === 'daily'
                    ? (float) $employee->pay_rate
                    : $revenue * ((float) $employee->pay_rate / 100);

                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'pay_type' => $employee->pay_type,
                    'pay_rate' => (float) $employee->pay_rate,
                    'amount' => round($amount, 2),
                    'payment_method' => $employee->pivot->payment_method,
                    'paid_at' => $employee->pivot->paid_at,
                    'journal_entry_id' => $employee->pivot->journal_entry_id,
                ];
            })
            ->filter(fn (array $wage) => ! $wage['paid_at'] && $wage['amount'] > 0)
            ->values();

        return response()->json(['employees' => $wages]);
    }

    public function payEmployeeWage(Request $request, Shift $shift)
    {
        abort_unless($shift->restaurant_id === $request->user()->restaurant_id, 403);
        abort_unless($request->user()->hasPermissionTo('pay_employee_wages', 'sanctum'), 403);
        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'payment_method' => 'required|string|max:50',
        ]);

        DB::transaction(function () use ($request, $shift, $validated): void {
            $lockedShift = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($lockedShift->status !== 'open') {
                throw ValidationException::withMessages([
                    'shift' => ['لا يمكن دفع أجر موظف من وردية مغلقة.'],
                ]);
            }

            $group = $lockedShift->shift_group_id
                ? ShiftGroup::whereKey($lockedShift->shift_group_id)->lockForUpdate()->first()
                : null;
            $employees = $this->employeesForWages($lockedShift, $group);
            $employee = $employees->firstWhere('id', (int) $validated['employee_id']);
            if (! $employee
                || $employee->restaurant_id !== $lockedShift->restaurant_id
                || $employee->status !== 'active'
                || ! $employee->pay_auto
                || ! in_array($employee->pay_type, ['daily', 'percentage'], true)) {
                throw ValidationException::withMessages([
                    'employee_id' => ['الموظف غير مشارك في هذه الوردية أو لا يستحق أجراً تلقائياً.'],
                ]);
            }

            if ($employee->pivot->paid_at) {
                throw ValidationException::withMessages([
                    'employee_id' => ['تم دفع أجر هذا الموظف مسبقاً.'],
                ]);
            }

            $amount = $employee->pay_type === 'daily'
                ? (float) $employee->pay_rate
                : $this->wageRevenue($lockedShift, $group) * ((float) $employee->pay_rate / 100);
            $amount = round($amount, 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'employee_id' => ['قيمة أجر الموظف تساوي صفراً ولا يمكن إنشاء قيد.'],
                ]);
            }

            $posting = app(JournalPostingService::class);
            // Validate the selected payment method and expense account now so
            // the cashier gets immediate feedback, but do not post a separate
            // wage entry. Wages are consolidated into the shift closing entry.
            $posting->assertConfiguredPaymentMethod(
                $lockedShift->restaurant_id,
                $validated['payment_method'],
            );
            $expenseAccountId = $employee->pay_account_id ?: $employee->user?->pay_account_id;
            $expenseAccount = $expenseAccountId
                ? Account::where('restaurant_id', $lockedShift->restaurant_id)
                    ->whereKey($expenseAccountId)
                    ->where('type', 'expense')
                    ->where('is_active', true)
                    ->first()
                : Account::firstOrCreate(
                    ['restaurant_id' => $lockedShift->restaurant_id, 'code' => '5000'],
                    ['name' => 'رواتب وأجور', 'type' => 'expense', 'opening_balance' => 0, 'is_active' => true],
                );
            if (! $expenseAccount) {
                throw ValidationException::withMessages([
                    'pay_account_id' => ['حساب مصروف الموظف غير صالح أو غير نشط.'],
                ]);
            }

            $relation = $group ? $group->employees() : $lockedShift->employees();
            if (! $group && ! $lockedShift->employees()->whereKey($employee->id)->exists()) {
                $relation->attach($employee->id);
            }
            $relation->updateExistingPivot($employee->id, [
                'payment_method' => $validated['payment_method'],
                'paid_at' => now(),
                'paid_amount' => $amount,
                'journal_entry_id' => null,
                'paid_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'journal_entry' => null,
            'message' => 'تم تسجيل دفع أجر الموظف، وسيُدرج في قيد إغلاق الوردية',
        ]);
    }

    private function wageReference(Employee $employee, string $workDate, Shift $shift, ?ShiftGroup $group): string
    {
        return $employee->pay_type === 'daily'
            ? 'WAGE-'.$employee->id.'-'.$workDate
            : 'WAGE-'.$employee->id.'-'.($group ? 'GROUP-'.$group->id : $shift->id);
    }

    private function employeesForWages(Shift $shift, ?ShiftGroup $group)
    {
        $employees = $group
            ? $group->employees()->with('user')->get()
            : $shift->employees()->with('user')->get();

        if (! $group && $shift->employee_id && ! $employees->contains('id', $shift->employee_id)) {
            $employee = $shift->employee()->with('user')->first();
            if ($employee) {
                $employee->setRelation('pivot', (object) [
                    'payment_method' => null,
                    'paid_at' => null,
                    'paid_amount' => null,
                    'journal_entry_id' => null,
                    'paid_by' => null,
                ]);
                $employees->push($employee);
            }
        }

        return $employees;
    }

    private function wageRevenue(Shift $shift, ?ShiftGroup $group): float
    {
        $shiftIds = $group ? $group->shifts()->pluck('id') : collect([$shift->id]);
        $orders = Order::whereIn('shift_id', $shiftIds)
            ->where(function ($query) {
                $query->where('payment_status', 'paid')->orWhere('status', 'completed');
            })
            ->get();
        $payments = Payment::where('paymentable_type', Order::class)
            ->whereIn('paymentable_id', $orders->pluck('id'))
            ->get();

        return (float) ($payments->isNotEmpty()
            ? $payments->sum('amount')
            : $orders->sum('total_amount'));
    }

    /**
     * Wage lines that get merged into the shift-closing journal entry.
     *
     * @return array{lines:array<int,array{account_id:int,debit?:float,credit?:float,description?:string}>,total:float}
     */
    private function shiftWageLines(Shift $shift, ?ShiftGroup $group, int $restaurantId): array
    {
        $posting = app(JournalPostingService::class);
        $wageRevenue = $this->wageRevenue($shift, $group);
        $employees = $this->employeesForWages($shift, $group);
        $lines = [];
        $total = 0.0;

        foreach ($employees as $employee) {
            if ((int) $employee->restaurant_id !== $restaurantId
                || $employee->status !== 'active'
                || ! $employee->pay_auto
                || ! in_array($employee->pay_type, ['daily', 'percentage'], true)) {
                continue;
            }

            $amount = (float) ($employee->pivot?->paid_amount ?? 0);
            if ($amount <= 0) {
                $amount = $employee->pay_type === 'daily'
                    ? (float) $employee->pay_rate
                    : $wageRevenue * ((float) $employee->pay_rate / 100);
            }
            $amount = round($amount, 2);
            if ($amount <= 0) {
                continue;
            }

            $expenseAccountId = $employee->pay_account_id ?: $employee->user?->pay_account_id;
            $expenseAccount = $expenseAccountId
                ? Account::where('restaurant_id', $restaurantId)
                    ->whereKey($expenseAccountId)
                    ->where('type', 'expense')
                    ->where('is_active', true)
                    ->first()
                : null;
            if (! $expenseAccount) {
                $expenseAccount = Account::firstOrCreate(
                    ['restaurant_id' => $restaurantId, 'code' => '5000'],
                    ['name' => 'رواتب وأجور', 'type' => 'expense', 'opening_balance' => 0, 'is_active' => true],
                );
            }

            $method = $employee->pivot?->payment_method ?: $this->defaultPaymentMethod($restaurantId);
            $paymentAccount = $posting->paymentAccount($restaurantId, (string) $method);

            $lines[] = [
                'account_id' => $expenseAccount->id,
                'debit' => $amount,
                'description' => 'أجر '.$employee->name.' - '.$employee->pay_type,
            ];
            $lines[] = [
                'account_id' => $paymentAccount->id,
                'credit' => $amount,
                'description' => 'دفع أجر '.$employee->name.' عبر '.$method,
            ];
            $total += $amount;
        }

        return ['lines' => $lines, 'total' => round($total, 4)];
    }

    /**
     * Wage details shown in the shift report.
     *
     * @return array{wages:array<int,array<string,mixed>>,wages_total:float}
     */
    private function shiftWages(Shift $shift, ?ShiftGroup $group): array
    {
        $wageRevenue = $this->wageRevenue($shift, $group);
        $employees = $this->employeesForWages($shift, $group);
        $wages = [];
        $total = 0.0;

        foreach ($employees as $employee) {
            if ($employee->status !== 'active'
                || ! $employee->pay_auto
                || ! in_array($employee->pay_type, ['daily', 'percentage'], true)) {
                continue;
            }

            $amount = (float) ($employee->pivot?->paid_amount ?? 0);
            if ($amount <= 0) {
                $amount = $employee->pay_type === 'daily'
                    ? (float) $employee->pay_rate
                    : $wageRevenue * ((float) $employee->pay_rate / 100);
            }
            $amount = round($amount, 4);
            if ($amount <= 0) {
                continue;
            }

            $wages[] = [
                'employee_id' => (int) $employee->id,
                'employee_name' => $employee->name,
                'pay_type' => $employee->pay_type,
                'pay_rate' => (float) $employee->pay_rate,
                'amount' => $amount,
                'payment_method' => $employee->pivot?->payment_method,
                'paid' => (bool) $employee->pivot?->paid_at,
                'paid_at' => $employee->pivot?->paid_at,
            ];
            $total += $amount;
        }

        return ['wages' => $wages, 'wages_total' => round($total, 4)];
    }

    private function defaultPaymentMethod(int $restaurantId): string
    {
        $settings = Setting::where('restaurant_id', $restaurantId)
            ->where('group', 'pos')
            ->where('key', 'payment_methods')
            ->value('value');
        $methods = $settings !== null ? json_decode($settings, true) : null;

        if (is_array($methods)) {
            foreach ($methods as $method) {
                if (! is_array($method)) {
                    continue;
                }
                $value = $method['value'] ?? $method['method'] ?? null;
                if (is_string($value) && trim($value) !== '' && ! empty($method['account_id'])) {
                    return $value;
                }
            }
        }

        throw ValidationException::withMessages([
            'payment_method' => ['لم تُضبط طرق دفع مرتبطة بحسابات؛ لا يمكن ترحيل أجور الوردية.'],
        ]);
    }

    private function userHasShiftPermission(User $user, string $permission): bool
    {
        return Permission::where('name', $permission)
            ->where('guard_name', 'sanctum')
            ->exists()
            && $user->hasPermissionTo($permission, 'sanctum');
    }

    private function closeAnyoneEnabled(int $restaurantId): bool
    {
        $value = Setting::where('restaurant_id', $restaurantId)
            ->where('group', 'pos')
            ->where('key', 'close_shift_anyone')
            ->value('value');

        return in_array(strtolower((string) $value), ['true', '1', 'yes', 'on'], true);
    }

    private function canCloseShift(User $user, Shift $shift): bool
    {
        $isOpener = (int) $shift->user_id === (int) $user->id
            || ($shift->group && (int) $shift->group->opened_by === (int) $user->id);
        if ($isOpener) {
            return true;
        }

        return $user->hasPermissionTo('close_shift', 'sanctum')
            && $this->closeAnyoneEnabled((int) $shift->restaurant_id);
    }

    /**
     * @return array{orders_count:int,total:float,by_payment_method:array<string,float>,cash:float}
     */
    private function revenueSummary(Shift $shift, ?int $userId = null): array
    {
        $query = Order::where('restaurant_id', $shift->restaurant_id)
            ->where('shift_id', $shift->id)
            ->where(function ($q) {
                $q->where('payment_status', 'paid')->orWhere('status', 'completed');
            });
        if ($userId !== null) {
            $query->where('cashier_id', $userId);
        }

        $orders = $query->with('payments')->get();
        $byPayment = [];
        foreach ($orders as $order) {
            foreach ($order->payments as $payment) {
                $method = $payment->method ?: 'cash';
                $byPayment[$method] = ($byPayment[$method] ?? 0) + (float) $payment->amount;
            }
            if ($order->payments->isEmpty()) {
                $method = $order->payment_method ?: 'cash';
                $byPayment[$method] = ($byPayment[$method] ?? 0) + (float) $order->total_amount;
            }
        }
        $byPayment = array_map(fn ($value) => round((float) $value, 4), $byPayment);

        $cash = 0.0;
        foreach ($byPayment as $method => $amount) {
            if (in_array(mb_strtolower(trim((string) $method)), ['cash', 'نقد', 'نقدي', 'cashbox'], true)) {
                $cash += (float) $amount;
            }
        }

        return [
            'orders_count' => $orders->count(),
            'total' => round((float) array_sum($byPayment), 4),
            'by_payment_method' => $byPayment,
            'cash' => round($cash, 4),
        ];
    }

    private function cashierBalancePayload(Shift $shift, ShiftCashierBalance $balance): array
    {
        $summary = $this->revenueSummary($shift, (int) $balance->user_id);
        $opening = (float) $balance->opening_cash;

        return [
            'user_id' => (int) $balance->user_id,
            'user_name' => $balance->user?->name,
            'opening_cash' => round($opening, 4),
            'opening_cash_set' => (bool) $balance->opening_cash_set,
            'revenue' => $summary['total'],
            'by_payment_method' => $summary['by_payment_method'],
            'cash_revenue' => $summary['cash'],
            'expected_cash' => round($opening + $summary['cash'], 4),
            'actual_cash' => $balance->actual_cash !== null ? round((float) $balance->actual_cash, 4) : null,
            'difference' => $balance->difference !== null ? round((float) $balance->difference, 4) : null,
            'orders_count' => $summary['orders_count'],
            'closed_at' => $balance->closed_at?->toISOString(),
        ];
    }

    public function current(Request $request)
    {
        $user = $request->user();
        $restaurantId = $user->restaurant_id;

        // The shift is shared by every POS user: a single open shift per restaurant.
        $shift = Shift::where('restaurant_id', $restaurantId)
            ->where('status', 'open')
            ->latest('id')
            ->with('employees', 'employee', 'user', 'group.employees')
            ->first();

        if (! $shift) {
            return response()->json([
                'shift' => null,
                'revenue' => null,
                'orders_count' => null,
                'balance' => null,
                'needs_opening_cash' => false,
                'can_close_shift' => false,
            ]);
        }

        $canViewAll = $user->hasPermissionTo('view_all_orders', 'sanctum')
            || $user->hasPermissionTo('view_all_shifts', 'sanctum');
        $canViewRevenue = $user->hasPermissionTo('view_pos', 'sanctum') || $canViewAll;
        $canClose = $this->canCloseShift($user, $shift);

        $groupEmployees = $shift->group?->employees()->get();
        if ($groupEmployees) {
            $shift->setRelation('employees', $groupEmployees);
        }

        $mySummary = $this->revenueSummary($shift, (int) $user->id);
        $myBalance = ShiftCashierBalance::where('shift_id', $shift->id)
            ->where('user_id', $user->id)
            ->first();
        $opening = $myBalance ? (float) $myBalance->opening_cash : 0.0;

        $response = [
            'shift' => $shift->load('user'),
            'wage_disbursement_due' => true,
            'revenue' => $canViewRevenue ? [
                'total' => $mySummary['total'],
                'by_payment_method' => $mySummary['by_payment_method'],
            ] : null,
            'orders_count' => $canViewRevenue ? $mySummary['orders_count'] : null,
            'balance' => [
                'user_id' => (int) $user->id,
                'user_name' => $user->name,
                'opening_cash' => round($opening, 4),
                'opening_cash_set' => (bool) ($myBalance?->opening_cash_set ?? false),
                'revenue' => $mySummary['total'],
                'cash_revenue' => $mySummary['cash'],
                'expected_cash' => round($opening + $mySummary['cash'], 4),
                'actual_cash' => $myBalance?->actual_cash !== null ? round((float) $myBalance->actual_cash, 4) : null,
                'difference' => $myBalance?->difference !== null ? round((float) $myBalance->difference, 4) : null,
                'orders_count' => $mySummary['orders_count'],
                'closed_at' => $myBalance?->closed_at?->toISOString(),
            ],
            'needs_opening_cash' => $user->hasPermissionTo('create_orders', 'sanctum')
                && ! (bool) ($myBalance?->opening_cash_set ?? false),
            'can_close_shift' => $canClose,
        ];

        if ($canViewAll || $canClose) {
            $balances = ShiftCashierBalance::where('shift_id', $shift->id)
                ->with('user')
                ->get()
                ->map(fn (ShiftCashierBalance $balance) => $this->cashierBalancePayload($shift, $balance))
                ->values();
            $shiftSummary = $this->revenueSummary($shift);
            $response['cashiers'] = $balances;
            $response['shift_revenue'] = [
                'total' => $shiftSummary['total'],
                'by_payment_method' => $shiftSummary['by_payment_method'],
            ];
            $response['shift_orders_count'] = $shiftSummary['orders_count'];
        }

        return response()->json($response);
    }

    public function setOpeningCash(Request $request, Shift $shift)
    {
        $user = $request->user();
        abort_unless($shift->restaurant_id === $user->restaurant_id, 403);
        abort_unless($user->hasPermissionTo('create_orders', 'sanctum'), 403);

        $validated = $request->validate([
            'opening_cash' => 'required|numeric|min:0',
        ]);

        $balance = DB::transaction(function () use ($shift, $user, $validated) {
            $lockedShift = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($lockedShift->status !== 'open') {
                throw ValidationException::withMessages([
                    'shift' => ['لا يمكن تحديد النقد الافتتاحي لوردية مغلقة.'],
                ]);
            }

            $balance = ShiftCashierBalance::firstOrCreate(
                ['shift_id' => $lockedShift->id, 'user_id' => $user->id],
                [
                    'restaurant_id' => $lockedShift->restaurant_id,
                    'opening_cash' => 0,
                    'opening_cash_set' => false,
                ]
            );
            $balance->update([
                'opening_cash' => round((float) $validated['opening_cash'], 4),
                'opening_cash_set' => true,
            ]);

            return $balance;
        });

        return response()->json([
            'balance' => $this->cashierBalancePayload($shift->fresh(), $balance->load('user')),
            'message' => 'تم حفظ النقد الافتتاحي الخاص بك',
        ]);
    }


    public function revenue(Request $request, Shift $shift)
    {
        abort_unless($shift->restaurant_id === $request->user()->restaurant_id, 403);
        abort_unless(
            $request->user()->hasPermissionTo('view_pos', 'sanctum')
                || $request->user()->hasPermissionTo('view_all_shifts', 'sanctum')
                || $request->user()->hasPermissionTo('close_shift', 'sanctum'),
            403
        );
        abort_unless(
            (int) $shift->user_id === (int) $request->user()->id
                || $request->user()->hasPermissionTo('view_all_shifts', 'sanctum'),
            403
        );

        $shifts = $shift->shift_group_id && $request->user()->hasPermissionTo('view_all_shifts', 'sanctum')
            ? $shift->group->shifts()->with('user')->get()
            : collect([$shift->load('user')]);

        $baseShift = $shifts->first() ?: $shift;
        $restaurantId = $shift->restaurant_id;

        // Per-cashier breakdown: each user who created orders in this shift.
        $allOrders = collect();
        $cashierIds = Order::where('restaurant_id', $restaurantId)
            ->where('shift_id', $shift->id)
            ->whereNotNull('cashier_id')
            ->distinct()
            ->pluck('cashier_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $cashiers = $cashierIds->map(function (int $cashierId) use ($shift, $restaurantId, &$allOrders) {
            $cashier = User::where('id', $cashierId)->where('restaurant_id', $restaurantId)->first();
            $orders = Order::where('restaurant_id', $restaurantId)
                ->where('shift_id', $shift->id)
                ->where('cashier_id', $cashierId)
                ->where(function ($query) {
                    $query->where('payment_status', 'paid')->orWhere('status', 'completed');
                })
                ->with(['table', 'customer', 'items.product', 'payments', 'warehouse:id,name'])
                ->orderBy('created_at')
                ->get();

            $byPayment = [];
            foreach ($orders as $order) {
                $orderMethods = [];
                foreach ($order->payments as $payment) {
                    $method = $payment->method ?: 'cash';
                    $byPayment[$method] = ($byPayment[$method] ?? 0) + (float) $payment->amount;
                    $orderMethods[$method] = ($orderMethods[$method] ?? 0) + (float) $payment->amount;
                }
                if ($order->payments->isEmpty()) {
                    $method = $order->payment_method ?: 'cash';
                    $byPayment[$method] = ($byPayment[$method] ?? 0) + (float) $order->total_amount;
                    $orderMethods[$method] = (float) $order->total_amount;
                }

                $allOrders->push([
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'type' => $order->type,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'table_name' => $order->table?->name,
                    'customer_name' => $order->customer?->name,
                    'warehouse_id' => $order->warehouse_id,
                    'warehouse_name' => $order->warehouse?->name,
                    'cashier_name' => $cashier?->name,
                    'items_count' => (int) $order->items->sum('quantity'),
                    'items' => $order->items->map(fn ($item) => [
                        'name' => $item->product?->name ?: $item->name,
                        'quantity' => (int) $item->quantity,
                        'total_price' => (float) $item->total_price,
                    ])->values(),
                    'total_amount' => (float) $order->total_amount,
                    'amount_paid' => (float) $order->amount_paid,
                    'change_due' => (float) $order->change_due,
                    'payment_methods' => $orderMethods,
                    'created_at' => $order->created_at?->toISOString(),
                ]);
            }

            return [
                'shift_id' => $shift->id,
                'user_id' => $cashierId,
                'user_name' => $cashier?->name,
                'total' => round((float) array_sum($byPayment), 4),
                'orders_count' => $orders->count(),
                'by_payment_method' => array_map(fn ($amount) => round($amount, 4), $byPayment),
            ];
        })->values();

        // If no orders exist yet, fall back to the opener so the report is not empty.
        if ($cashiers->isEmpty()) {
            $cashiers = collect([[
                'shift_id' => $shift->id,
                'user_id' => $shift->user_id,
                'user_name' => $shift->user?->name,
                'total' => 0.0,
                'orders_count' => 0,
                'by_payment_method' => [],
            ]]);
        }

        $byPayment = [];
        foreach ($cashiers as $cashier) {
            foreach ($cashier['by_payment_method'] as $method => $amount) {
                $byPayment[$method] = ($byPayment[$method] ?? 0) + $amount;
            }
        }

        $shiftDates = collect([$shift->shift_date])
            ->filter()
            ->map(fn ($date) => \Carbon\Carbon::parse($date)->toDateString())
            ->unique()
            ->values();

        $expenses = \App\Models\Expense::where('restaurant_id', $restaurantId)
            ->where('shift_id', $shift->id)
            ->whereIn(DB::raw('DATE(expense_date)'), $shiftDates->all())
            ->with('user')
            ->orderBy('expense_date')
            ->get()
            ->map(fn (\App\Models\Expense $expense) => [
                'id' => $expense->id,
                'category' => $expense->category,
                'description' => $expense->description,
                'amount' => (float) $expense->amount,
                'payment_method' => $expense->payment_method,
                'expense_date' => $expense->expense_date?->toDateString(),
                'user_name' => $expense->user?->name,
            ])->values();

        $ordersTotal = round((float) $cashiers->sum('total'), 4);
        $wageData = $this->shiftWages($shift, $shift->shift_group_id ? $shift->group : null);
        $wagesTotal = (float) $wageData['wages_total'];

        // Wages are an operating expense of the shift. They are merged into
        // the expense details (and its total) instead of being shown as a
        // separate section, so the shift report matches the closing entry.
        $wageExpenses = collect($wageData['wages'])->map(function (array $wage) use ($shift): array {
            $typeLabel = $wage['pay_type'] === 'daily'
                ? 'أجر يومي'
                : ($wage['pay_type'] === 'percentage' ? 'نسبة من المبيعات' : 'أجر');

            return [
                'id' => 'wage-'.$wage['employee_id'],
                'category' => 'أجور موظفين',
                'description' => trim(($wage['employee_name'] ?? '').' - '.$typeLabel, ' -'),
                'amount' => (float) $wage['amount'],
                'payment_method' => $wage['payment_method'] ?: 'cash',
                'expense_date' => $shift->shift_date
                    ? Carbon::parse($shift->shift_date)->toDateString()
                    : null,
                'user_name' => $wage['employee_name'],
                'source' => 'wage',
            ];
        });

        $expenses = $expenses->concat($wageExpenses)->values();
        $expensesTotal = round((float) $expenses->sum('amount'), 4);

        return response()->json([
            'total' => $ordersTotal,
            'by_payment_method' => array_map(fn ($v) => round($v, 4), $byPayment),
            'orders_count' => $allOrders->count(),
            'cashiers' => $cashiers->values(),
            'orders' => $allOrders->values(),
            'expenses' => $expenses,
            'expenses_total' => $expensesTotal,
            'wages' => $wageData['wages'],
            'wages_total' => $wagesTotal,
            'net_total' => round($ordersTotal - $expensesTotal, 4),
            'opening_cash' => (float) ($baseShift->opening_cash ?? 0),
            'expected_cash' => (float) ($baseShift->expected_cash ?? 0),
            'actual_cash' => (float) ($baseShift->actual_cash ?? 0),
            'difference' => (float) ($baseShift->difference ?? 0),
        ]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->hasPermissionTo('open_shift', 'sanctum'), 403);
        $validated = $request->validate([
            'employee_ids' => 'sometimes|array',
            'employee_ids.*' => 'integer|distinct',
            'name' => 'required|string|max:100',
            'shift_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'opening_cash' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $restaurantId = $actor->restaurant_id;
        $employeeIds = array_values(array_unique(array_map('intval', $validated['employee_ids'] ?? [])));
        if ($employeeIds && ! $actor->hasPermissionTo('manage_shift_employees', 'sanctum')) {
            abort(403);
        }
        if ($employeeIds) {
            $eligibleIds = Employee::where('restaurant_id', $restaurantId)
                ->where('status', 'active')
                ->where('pay_auto', true)
                ->whereIn('pay_type', ['daily', 'percentage'])
                ->whereIn('id', $employeeIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if (count($eligibleIds) !== count($employeeIds)) {
                throw ValidationException::withMessages([
                    'employee_ids' => ['يمكن اختيار الموظفين النشطين ذوي الأجر اليومي أو النسبة فقط.'],
                ]);
            }
        }

        $result = DB::transaction(function () use ($validated, $employeeIds, $restaurantId, $actor) {
            // Only one open shift is allowed for the whole restaurant / POS.
            $openShift = Shift::where('restaurant_id', $restaurantId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();
            if ($openShift) {
                throw ValidationException::withMessages([
                    'shift' => ['يوجد وردية مفتوحة بالفعل. لا يمكن فتح وردية ثانية قبل إغلاقها.'],
                ]);
            }

            $group = ShiftGroup::where('restaurant_id', $restaurantId)
                ->where('status', 'open')
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if (! $group) {
                $group = ShiftGroup::create([
                    'restaurant_id' => $restaurantId,
                    'opened_by' => $actor->id,
                    'name' => $validated['name'],
                    'shift_date' => $validated['shift_date'],
                    'status' => 'open',
                ]);
            }
            $group->forceFill(['status' => 'open'])->save();

            $shift = Shift::where('shift_group_id', $group->id)
                ->where('status', 'open')
                ->first();
            if (! $shift) {
                $shift = Shift::create([
                    'restaurant_id' => $restaurantId,
                    'shift_group_id' => $group->id,
                    'user_id' => $actor->id,
                    'name' => 'وردية #'.$group->id.' - '.Carbon::parse($validated['shift_date'])->format('Y-m-d'),
                    'shift_date' => $validated['shift_date'],
                    'start_time' => Carbon::parse($validated['start_time'])->format('H:i:s'),
                    'end_time' => ! empty($validated['end_time'])
                        ? Carbon::parse($validated['end_time'])->format('H:i:s')
                        : '23:59:00',
                    'status' => 'open',
                    'opening_cash' => (float) ($validated['opening_cash'] ?? 0),
                    'expected_cash' => (float) ($validated['opening_cash'] ?? 0),
                    'actual_cash' => 0,
                    'difference' => 0,
                    'notes' => $validated['notes'] ?? null,
                ]);
            }

            // Each user keeps their own opening cash inside the shared shift.
            $openingCash = round((float) ($validated['opening_cash'] ?? 0), 4);
            ShiftCashierBalance::updateOrCreate(
                ['shift_id' => $shift->id, 'user_id' => $actor->id],
                [
                    'restaurant_id' => $restaurantId,
                    'opening_cash' => $openingCash,
                    'opening_cash_set' => true,
                ]
            );

            if ($employeeIds) {
                $group->employees()->sync($employeeIds);
            }

            $group->forceFill([
                'name' => 'وردية #'.$group->id.' - '.Carbon::parse($group->shift_date)->format('Y-m-d'),
            ])->save();

            $shift->setRelation('employees', $group->employees()->get());

            return ['group' => $group, 'shift' => $shift->load('user'), 'shifts' => collect([$shift])];
        });

        return response()->json([
            'group' => $result['group']->load('employees'),
            'shift' => $result['shift'],
            'shifts' => $result['shifts']->values(),
            'message' => 'تم فتح الوردية، ويمكن لكل كاشير إنشاء الطلبات ضمنها',
        ], 201);
    }


    public function show(Shift $shift)
    {
        $user = auth()->user();
        abort_unless($shift->restaurant_id === $user->restaurant_id, 403);
        abort_unless(
            (int) $shift->user_id === (int) $user->id
                || $user->hasPermissionTo('view_all_shifts', 'sanctum'),
            403
        );

        return response()->json(['shift' => $shift->load('user', 'employee', 'employees')]);
    }

    public function update(Request $request, Shift $shift)
    {
        $user = $request->user();
        abort_unless($shift->restaurant_id === $user->restaurant_id, 403);

        if ($request->input('status') === 'closed') {
            $isOpener = (int) $shift->user_id === (int) $user->id
                || ($shift->group && (int) $shift->group->opened_by === (int) $user->id);
            if (! $isOpener) {
                abort_unless($user->hasPermissionTo('close_shift', 'sanctum'), 403);
                if (! $this->closeAnyoneEnabled((int) $shift->restaurant_id)) {
                    abort(403, 'فقط من فتح الوردية يمكنه إغلاقها. فعّل خيار السماح لأي مستخدم بإغلاق الوردية من الإعدادات للسماح بذلك.');
                }
            }
        } else {
            abort_unless(
                (int) $shift->user_id === (int) $user->id
                    || $user->hasPermissionTo('view_all_shifts', 'sanctum'),
                403
            );
        }

        $validated = $request->validate([
            'actual_cash' => 'sometimes|numeric|min:0',
            'cashiers' => 'sometimes|array',
            'cashiers.*.user_id' => 'required|integer',
            'cashiers.*.actual_cash' => 'required|numeric|min:0',
            'status' => 'sometimes|in:open,closed',
            'notes' => 'sometimes|string',
            'end_time' => 'sometimes|date_format:H:i',
        ]);

        $journalCreated = false;
        if ($request->has('status') && $request->status === 'closed') {
            DB::transaction(function () use ($request, $user, $shift, &$validated, &$journalCreated) {
                $lockedShift = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
                if ($lockedShift->status !== 'open') {
                    throw ValidationException::withMessages([
                        'status' => ['هذه الوردية مغلقة بالفعل.'],
                    ]);
                }
                $group = $lockedShift->shift_group_id
                    ? ShiftGroup::whereKey($lockedShift->shift_group_id)->lockForUpdate()->first()
                    : null;

                $wageRevenue = $this->wageRevenue($lockedShift, $group);
                $assignedEmployees = $this->employeesForWages($lockedShift, $group)
                    ->filter(fn (Employee $employee) => $employee->status === 'active'
                        && $employee->pay_auto
                        && in_array($employee->pay_type, ['daily', 'percentage'], true)
                        && ($employee->pay_type === 'daily'
                            ? (float) $employee->pay_rate
                            : $wageRevenue * ((float) $employee->pay_rate / 100)) > 0);
                $unpaidEmployees = [];
                foreach ($assignedEmployees as $assignedEmployee) {
                    $reference = $this->wageReference(
                        $assignedEmployee,
                        Carbon::parse($lockedShift->shift_date)->toDateString(),
                        $lockedShift,
                        $group
                    );
                    $isPaid = $assignedEmployee->pivot->paid_at
                        || JournalEntry::where('restaurant_id', $lockedShift->restaurant_id)
                            ->where('reference', $reference)
                            ->exists();
                    if (! $isPaid) {
                        $unpaidEmployees[] = $assignedEmployee->name;
                    }
                }
                if (! empty($unpaidEmployees)) {
                    $names = implode('، ', $unpaidEmployees);
                    $message = count($unpaidEmployees) === 1
                        ? 'لا يمكن إغلاق الوردية قبل تسوية أجور الموظفين. تبقى الموظف '.$names.' لم يعطَ أجره.'
                        : 'لا يمكن إغلاق الوردية قبل تسوية أجور الموظفين. تبقى الموظفون: '.$names.' لم يُعطَوا أجورهم.';
                    throw new HttpResponseException(response()->json([
                        'message' => $message,
                        'errors' => ['status' => [$message]],
                    ], 422));
                }

                $restaurantId = $lockedShift->restaurant_id;

                // Make sure every user who worked in this shift has their own balance row.
                $cashierIds = Order::where('restaurant_id', $restaurantId)
                    ->where('shift_id', $lockedShift->id)
                    ->whereNotNull('cashier_id')
                    ->distinct()
                    ->pluck('cashier_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $cashierIds[] = (int) $lockedShift->user_id;
                foreach (array_unique($cashierIds) as $cashierId) {
                    $isOpener = $cashierId === (int) $lockedShift->user_id;
                    ShiftCashierBalance::firstOrCreate(
                        ['shift_id' => $lockedShift->id, 'user_id' => $cashierId],
                        [
                            'restaurant_id' => $restaurantId,
                            'opening_cash' => $isOpener ? (float) ($lockedShift->opening_cash ?? 0) : 0,
                            'opening_cash_set' => $isOpener,
                        ]
                    );
                }

                $balances = ShiftCashierBalance::where('shift_id', $lockedShift->id)
                    ->with('user')
                    ->get();

                $actualByUser = [];
                foreach (($validated['cashiers'] ?? []) as $cashierInput) {
                    $actualByUser[(int) $cashierInput['user_id']] = (float) $cashierInput['actual_cash'];
                }

                $posting = app(JournalPostingService::class);
                $totals = [
                    'opening' => 0.0,
                    'expected' => 0.0,
                    'actual' => 0.0,
                    'difference' => 0.0,
                ];
                $wageData = $this->shiftWageLines($lockedShift, $group, $restaurantId);
                $openerCashierId = (int) $lockedShift->user_id;
                $openerLines = [];
                $entryDate = Carbon::parse($lockedShift->shift_date)->toDateString();

                foreach ($balances as $balance) {
                    $cashierId = (int) $balance->user_id;
                    $cashier = $balance->user;
                    if (! $cashier) {
                        continue;
                    }
                    $summary = $this->revenueSummary($lockedShift, $cashierId);
                    $openingCash = (float) $balance->opening_cash;
                    $expected = round($openingCash + $summary['cash'], 4);

                    if (array_key_exists($cashierId, $actualByUser)) {
                        $actual = round((float) $actualByUser[$cashierId], 4);
                    } elseif ($request->has('actual_cash') && $cashierId === (int) $user->id) {
                        $actual = round((float) $validated['actual_cash'], 4);
                    } else {
                        $actual = $expected;
                    }
                    $difference = round($actual - $expected, 4);

                    $balance->update([
                        'expected_cash' => $expected,
                        'actual_cash' => $actual,
                        'difference' => $difference,
                        'closed_at' => now(),
                    ]);

                    $totals['opening'] += $openingCash;
                    $totals['expected'] += $expected;
                    $totals['actual'] += $actual;
                    $totals['difference'] += $difference;

                    if ($summary['total'] <= 0) {
                        continue;
                    }

                    // Each cashier's collections are posted directly against the
                    // configured payment-method accounts and credited to that
                    // cashier's own sales-revenue sub-account.
                    $cashierRevenueAccount = app(CashierAccountService::class)
                        ->ensureRevenueAccountFor($cashier);

                    $lines = [];
                    foreach ($summary['by_payment_method'] as $method => $amount) {
                        $amount = round((float) $amount, 2);
                        if ($amount <= 0) {
                            continue;
                        }
                        $methodAccount = $posting->paymentAccount($restaurantId, (string) $method);
                        $lines[] = [
                            'account_id' => $methodAccount->id,
                            'debit' => $amount,
                            'description' => 'تحصيل وردية - '.$method.' - '.$cashier->name,
                        ];
                        $lines[] = [
                            'account_id' => $cashierRevenueAccount->id,
                            'credit' => $amount,
                            'description' => 'إيراد مبيعات '.$cashier->name.' - '.$method,
                        ];
                    }
                    if (empty($lines)) {
                        continue;
                    }

                    if ($cashierId === $openerCashierId) {
                        // The opener entry also carries the shift wages below.
                        $openerLines = array_merge($openerLines, $lines);
                    } else {
                        $posting->post(
                            $restaurantId,
                            'SH-',
                            'SHIFT-'.$lockedShift->id.'-U'.$cashierId,
                            $entryDate,
                            'قيد إغلاق الوردية #'.$lockedShift->id.' - '.$cashier->name,
                            $user->id,
                            $lines
                        );
                        $journalCreated = true;
                    }
                }

                // Wages are merged into the opener's closing entry so a single
                // entry reflects revenue, expenses and wages for the shift.
                if (! empty($wageData['lines'])) {
                    $openerLines = array_merge($openerLines, $wageData['lines']);
                }

                if (! empty($openerLines)) {
                    $posting->post(
                        $restaurantId,
                        'SH-',
                        'SHIFT-'.$lockedShift->id,
                        $entryDate,
                        'قيد إغلاق الوردية #'.$lockedShift->id.' - '.($lockedShift->user?->name ?? ''),
                        $user->id,
                        $openerLines
                    );
                    $journalCreated = true;
                }

                $validated['wages_total'] = $wageData['total'];

                $validated['expected_cash'] = round($totals['expected'], 4);
                $validated['actual_cash'] = round($totals['actual'], 4);
                $validated['difference'] = round($totals['difference'], 4);
                $validated['status'] = 'closed';
                $validated['closed_at'] = now();
                if (empty($validated['end_time'])) {
                    $validated['end_time'] = now()->format('H:i:s');
                }
                unset($validated['cashiers']);

                if (isset($validated['end_time']) && is_string($validated['end_time']) && strlen($validated['end_time']) === 5) {
                    $validated['end_time'] .= ':00';
                }

                $lockedShift->update($validated);

                // Defensive: close any other leftovers in the same group.
                if ($group) {
                    Shift::where('shift_group_id', $group->id)
                        ->where('status', 'open')
                        ->where('id', '!=', $lockedShift->id)
                        ->update([
                            'status' => 'closed',
                            'closed_at' => now(),
                            'end_time' => now()->format('H:i:s'),
                        ]);
                    if (! Shift::where('shift_group_id', $group->id)->where('status', 'open')->exists()) {
                        $group->update(['status' => 'closed']);
                    }
                }
            });

            return response()->json([
                'shift' => $shift->fresh()->load('user'),
                'revenue' => null,
                'message' => $journalCreated
                    ? 'تم إغلاق الوردية وإنشاء قيود الوردية بنجاح'
                    : 'تم إغلاق الوردية بنجاح',
            ]);
        }

        unset($validated['cashiers']);

        if ($request->has('actual_cash')) {
            $expected = (float) ($shift->expected_cash ?? $shift->opening_cash ?? 0);
            $validated['difference'] = (float) $validated['actual_cash'] - $expected;
        }
        if (isset($validated['end_time']) && is_string($validated['end_time']) && strlen($validated['end_time']) === 5) {
            $validated['end_time'] = $validated['end_time'].':00';
        }
        $shift->update($validated);

        return response()->json([
            'shift' => $shift->fresh()->load('user'),
            'message' => 'تم تحديث الوردية',
        ]);
    }


    public function destroy(Shift $shift)
    {
        $user = auth()->user();
        abort_unless($shift->restaurant_id === $user->restaurant_id, 403);
        abort_unless($user->hasPermissionTo('view_all_shifts', 'sanctum'), 403);
        $shift->delete();

        return response()->json(['message' => 'تم حذف الوردية']);
    }
}
