<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Purchase;
use App\Services\JournalPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = Expense::where('restaurant_id', $restaurantId)->with('user', 'account');

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }
        if ($request->has('from')) {
            $query->where('expense_date', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->where('expense_date', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', '%'.$search.'%')
                    ->orWhere('category', 'like', '%'.$search.'%');
            });
        }

        return response()->json($query->orderByDesc('expense_date')->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category' => 'required|string|max:100',
            'account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $request->user()->restaurant_id)
                    ->where('type', 'expense')
                    ->where('is_active', true)),
            ],
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'description' => 'nullable|string',
            'receipt' => 'nullable|string',
            'payment_method' => 'required|string|max:50',
            'is_approved' => 'sometimes|boolean',
            'shift_id' => 'nullable|integer',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['user_id'] = $request->user()->id;
        if (! empty($validated['shift_id'])) {
            $shift = \App\Models\Shift::where('restaurant_id', $request->user()->restaurant_id)
                ->where('id', $validated['shift_id'])
                ->first();
            if (! $shift) {
                unset($validated['shift_id']);
            }
        } else {
            unset($validated['shift_id']);
        }
        $validated['amount'] = round((float) $validated['amount'], 2);
        if ($validated['amount'] <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['يجب أن يكون مبلغ المصروف أكبر من صفر بعد التقريب لعملتين.'],
            ]);
        }
        $validated['is_approved'] = $request->boolean('is_approved', false);
        $expense = DB::transaction(function () use ($validated, $request) {
            $expense = Expense::create($validated);
            $paymentAccount = app(JournalPostingService::class)
                ->paymentAccount($validated['restaurant_id'], $validated['payment_method']);
            app(JournalPostingService::class)->post(
                $validated['restaurant_id'],
                'EX-',
                'EXPENSE-'.$expense->id,
                $validated['expense_date'],
                'مصروف: '.$validated['category'],
                $request->user()->id,
                [
                    [
                        'account_id' => $validated['account_id'],
                        'debit' => $validated['amount'],
                        'description' => $validated['description'] ?? null,
                    ],
                    [
                        'account_id' => $paymentAccount->id,
                        'credit' => $validated['amount'],
                        'description' => $validated['description'] ?? null,
                    ],
                ]
            );

            return $expense;
        });

        return response()->json(['expense' => $expense->load('user', 'account'), 'message' => 'تم إنشاء المصروف وقيده'], 201);
    }

    public function show(Expense $expense)
    {
        if ($expense->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        return response()->json(['expense' => $expense->load('user', 'account')]);
    }

    public function update(Request $request, Expense $expense)
    {
        if ($expense->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'category' => 'sometimes|string|max:100',
            'account_id' => [
                'sometimes',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $expense->restaurant_id)
                    ->where('type', 'expense')
                    ->where('is_active', true)),
            ],
            'amount' => 'sometimes|numeric|min:0.01',
            'expense_date' => 'sometimes|date',
            'description' => 'sometimes|nullable|string',
            'payment_method' => 'sometimes|string|max:50',
            'is_approved' => 'sometimes|boolean',
            'approved_by' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('restaurant_id', $expense->restaurant_id),
            ],
        ]);
        if (array_key_exists('amount', $validated)) {
            $validated['amount'] = round((float) $validated['amount'], 2);
            if ($validated['amount'] <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['يجب أن يكون مبلغ المصروف أكبر من صفر بعد التقريب لعملتين.'],
                ]);
            }
        }
        $financialFields = ['category', 'account_id', 'amount', 'expense_date', 'description', 'payment_method'];
        $repost = count(array_intersect($financialFields, array_keys($validated))) > 0;
        DB::transaction(function () use ($expense, $validated, $repost, $request) {
            $expense->update($validated);
            if ($repost) {
                if (! $expense->account_id) {
                    abort(422, 'يجب ربط المصروف بحساب مصروفات قبل قيده.');
                }
                JournalEntry::where('restaurant_id', $expense->restaurant_id)
                    ->where('reference', 'EXPENSE-'.$expense->id)
                    ->delete();
                if (! $expense->payment_method) {
                    throw ValidationException::withMessages([
                        'payment_method' => ['حدد طريقة دفع مرتبطة بحساب قبل ترحيل هذا المصروف.'],
                    ]);
                }
                $paymentAccount = app(JournalPostingService::class)
                    ->paymentAccount($expense->restaurant_id, $expense->payment_method);
                app(JournalPostingService::class)->post(
                    $expense->restaurant_id,
                    'EX-',
                    'EXPENSE-'.$expense->id,
                    $expense->expense_date->toDateString(),
                    'مصروف: '.$expense->category,
                    $request->user()->id,
                    [
                        [
                            'account_id' => $expense->account_id,
                            'debit' => $expense->amount,
                            'description' => $expense->description,
                        ],
                        [
                            'account_id' => $paymentAccount->id,
                            'credit' => $expense->amount,
                            'description' => $expense->description,
                        ],
                    ]
                );
            }
        });

        return response()->json(['expense' => $expense->fresh()->load('user', 'account'), 'message' => 'تم تحديث المصروف وقيده']);
    }

    public function destroy(Expense $expense)
    {
        if ($expense->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        DB::transaction(function () use ($expense) {
            JournalEntry::where('restaurant_id', $expense->restaurant_id)
                ->where('reference', 'EXPENSE-'.$expense->id)
                ->delete();
            $expense->delete();
        });

        return response()->json(['message' => 'تم حذف المصروف']);
    }
}

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $payments = Payment::where('restaurant_id', $restaurantId)
            ->with('user')
            ->orderByDesc('paid_at')
            ->paginate(15);

        return response()->json($payments);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'paymentable_type' => 'required|string|max:100',
            'paymentable_id' => 'required|integer',
            'amount' => 'required|numeric|min:0',
            'method' => 'required|string|max:50',
            'reference' => 'nullable|string',
            'transaction_id' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
        if ($validated['paymentable_type'] === Purchase::class) {
            throw ValidationException::withMessages([
                'paymentable_type' => ['سداد المشتريات يتم من خلال مسار طلب الشراء لتحديث القيد والرصيد معًا.'],
            ]);
        }
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['user_id'] = $request->user()->id;
        $validated['paid_at'] = now();
        $payment = Payment::create($validated);

        return response()->json(['payment' => $payment->load('user'), 'message' => 'تم تسجيل الدفعة'], 201);
    }

    public function show(Payment $payment)
    {
        if ($payment->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        return response()->json(['payment' => $payment->load('user')]);
    }

    public function destroy(Payment $payment)
    {
        if ($payment->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        if ($payment->paymentable_type === Purchase::class) {
            return response()->json(['message' => 'لا يمكن حذف دفعة شراء منفردة؛ احذف طلب الشراء المرتبط بها.'], 409);
        }
        $payment->delete();

        return response()->json(['message' => 'تم حذف الدفعة']);
    }
}
