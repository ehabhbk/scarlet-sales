<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalEntryController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = JournalEntry::where('restaurant_id', $restaurantId)->with('lines.account', 'creator');

        if ($request->filled('from')) {
            $query->where('entry_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('entry_date', '<=', $request->input('to'));
        }
        if ($request->filled('entry_number')) {
            $query->where('entry_number', 'like', '%'.$request->input('entry_number').'%');
        }
        if ($request->filled('search')) {
            $search = '%'.trim((string) $request->input('search')).'%';
            $query->where(function ($q) use ($search) {
                $q->where('entry_number', 'like', $search)
                    ->orWhere('reference', 'like', $search)
                    ->orWhere('description', 'like', $search);
            });
        }

        $perPage = min(max($request->integer('per_page', 50), 1), 200);

        return response()->json(
            $query->orderByDesc('entry_date')
                ->orderByDesc('id')
                ->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'entry_date' => 'required|date',
            'reference' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:accounts,id',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.description' => 'nullable|string|max:255',
        ]);

        $restaurantId = $request->user()->restaurant_id;
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($validated['lines'] as $line) {
            $totalDebit += (float) ($line['debit'] ?? 0);
            $totalCredit += (float) ($line['credit'] ?? 0);
        }
        if (abs($totalDebit - $totalCredit) > 0.001) {
            return response()->json(['message' => 'مجموع المدين يجب أن يساوي مجموع الدائن'], 422);
        }

        // Validate accounts belong to restaurant
        foreach ($validated['lines'] as $line) {
            $account = Account::where('id', $line['account_id'])->where('restaurant_id', $restaurantId)->first();
            if (!$account) {
                return response()->json(['message' => 'حساب غير صالح'], 422);
            }
        }

        $entry = DB::transaction(function () use ($validated, $request, $restaurantId) {
            DB::table('restaurants')->where('id', $restaurantId)->lockForUpdate()->first();
            $entry = JournalEntry::create([
                'restaurant_id' => $restaurantId,
                'entry_number' => JournalEntry::nextEntryNumber($restaurantId),
                'entry_date' => $validated['entry_date'],
                'reference' => $validated['reference'] ?? null,
                'description' => $validated['description'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            foreach ($validated['lines'] as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit' => (float) ($line['debit'] ?? 0),
                    'credit' => (float) ($line['credit'] ?? 0),
                    'description' => $line['description'] ?? null,
                ]);
            }
            return $entry;
        });

        return response()->json(['entry' => $entry->load('lines.account', 'creator'), 'message' => 'تم إنشاء قيد اليومية'], 201);
    }

    public function show(JournalEntry $journalEntry)
    {
        if ($journalEntry->restaurant_id !== auth()->user()->restaurant_id) abort(403);
        return response()->json(['entry' => $journalEntry->load('lines.account', 'creator')]);
    }

    public function update(Request $request, JournalEntry $journalEntry)
    {
        if ($journalEntry->restaurant_id !== auth()->user()->restaurant_id) abort(403);
        $validated = $request->validate([
            'entry_date' => 'sometimes|date',
            'reference' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'lines' => 'sometimes|array|min:2',
            'lines.*.account_id' => 'required_with:lines|exists:accounts,id',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.description' => 'nullable|string|max:255',
        ]);

        $restaurantId = $request->user()->restaurant_id;

        if (isset($validated['lines'])) {
            $totalDebit = 0; $totalCredit = 0;
            foreach ($validated['lines'] as $line) {
                $totalDebit += (float) ($line['debit'] ?? 0);
                $totalCredit += (float) ($line['credit'] ?? 0);
            }
            if (abs($totalDebit - $totalCredit) > 0.001) {
                return response()->json(['message' => 'مجموع المدين يجب أن يساوي مجموع الدائن'], 422);
            }
            foreach ($validated['lines'] as $line) {
                $account = Account::where('id', $line['account_id'])->where('restaurant_id', $restaurantId)->first();
                if (!$account) return response()->json(['message' => 'حساب غير صالح'], 422);
            }
        }

        DB::transaction(function () use ($journalEntry, $validated, $request) {
            $journalEntry->update(collect($validated)->except('lines')->all());
            if (isset($validated['lines'])) {
                $journalEntry->lines()->delete();
                foreach ($validated['lines'] as $line) {
                    $journalEntry->lines()->create([
                        'account_id' => $line['account_id'],
                        'debit' => (float) ($line['debit'] ?? 0),
                        'credit' => (float) ($line['credit'] ?? 0),
                        'description' => $line['description'] ?? null,
                    ]);
                }
            }
        });

        return response()->json(['entry' => $journalEntry->fresh()->load('lines.account', 'creator'), 'message' => 'تم تحديث قيد اليومية']);
    }

    public function destroy(JournalEntry $journalEntry)
    {
        if ($journalEntry->restaurant_id !== auth()->user()->restaurant_id) abort(403);
        $journalEntry->delete();
        return response()->json(['message' => 'تم حذف قيد اليومية']);
    }
}