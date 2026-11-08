<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingController extends Controller
{
    private function balanceFor(Account $account, ?string $from = null, ?string $to = null): float
    {
        $q = JournalEntryLine::where('account_id', $account->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.restaurant_id', $account->restaurant_id)
            ->whereNull('journal_entries.deleted_at');
        if ($from) $q->where('journal_entries.entry_date', '>=', $from);
        if ($to) $q->where('journal_entries.entry_date', '<=', $to);
        $debit = (float) $q->sum('journal_entry_lines.debit');
        $credit = (float) (clone $q)->sum('journal_entry_lines.credit');
        $net = $debit - $credit;
        // Assets & expenses: debit increases; liabilities/equity/revenue: credit increases
        if (in_array($account->type, ['asset', 'expense'])) {
            return (float) $account->opening_balance + $net;
        }
        return (float) $account->opening_balance - $net;
    }

    public function chartOfAccounts(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $accounts = Account::where('restaurant_id', $restaurantId)->with('parent')->orderBy('code')->get();
        $from = $request->get('from');
        $to = $request->get('to');
        $data = $accounts->map(function (Account $a) use ($from, $to) {
            return [
                'id' => $a->id,
                'parent_id' => $a->parent_id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'is_active' => $a->is_active,
                'balance' => $this->balanceFor($a, $from, $to),
            ];
        });
        return response()->json(['accounts' => $data]);
    }

    public function ledger(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $accountId = $request->get('account_id');
        if (!$accountId) {
            return response()->json(['message' => 'account_id مطلوب'], 422);
        }
        $account = Account::where('id', $accountId)->where('restaurant_id', $restaurantId)->firstOrFail();
        $from = $request->get('from');
        $to = $request->get('to');
        $includeChildren = $request->boolean('include_children', false);

        // Determine the set of accounts to include (the selected one + descendants).
        $accountIds = [$account->id];
        if ($includeChildren) {
            $all = Account::where('restaurant_id', $restaurantId)->get();
            $descendants = function (int $parentId) use (&$descendants, $all): array {
                $ids = [];
                foreach ($all->where('parent_id', $parentId) as $child) {
                    $ids[] = (int) $child->id;
                    $ids = array_merge($ids, $descendants((int) $child->id));
                }
                return $ids;
            };
            $accountIds = array_merge($accountIds, $descendants($account->id));
        }
        $accountIds = array_values(array_unique($accountIds));
        $accountsById = Account::whereIn('id', $accountIds)->get()->keyBy('id');

        $q = JournalEntryLine::whereIn('account_id', $accountIds)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.restaurant_id', $restaurantId)
            ->whereNull('journal_entries.deleted_at')
            ->select('journal_entry_lines.*', 'journal_entries.entry_number', 'journal_entries.entry_date', 'journal_entries.description as entry_description', 'journal_entries.reference')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entry_lines.id');
        if ($from) $q->where('journal_entries.entry_date', '>=', $from);
        if ($to) $q->where('journal_entries.entry_date', '<=', $to);

        $lines = $q->get();
        $running = 0.0;
        // Opening balance = sum of opening balances of included accounts.
        foreach ($accountIds as $aid) {
            $a = $accountsById->get($aid);
            $running += in_array($a->type, ['asset', 'expense'])
                ? (float) $a->opening_balance
                : -(float) $a->opening_balance; // credit-nature: opening balance is credit
        }
        // Actually running balance convention: asset/expense: debit-credit; others: credit-debit.
        // Recompute opening properly:
        $running = 0.0;
        foreach ($accountIds as $aid) {
            $a = $accountsById->get($aid);
            if (in_array($a->type, ['asset', 'expense'])) {
                $running += (float) $a->opening_balance;
            } else {
                $running -= (float) $a->opening_balance;
            }
        }
        // We'll track signed (debit - credit) for asset/expense; for credit-nature we invert at display? 
        // To keep a single running column, use net = debit-credit for all, then for credit-nature accounts
        // the balance sign is opposite. Simpler: per-line signed = debit-credit, running accumulates signed.
        // Opening for credit-nature accounts should be negative of opening_balance.
        $running = 0.0;
        foreach ($accountIds as $aid) {
            $a = $accountsById->get($aid);
            if (in_array($a->type, ['asset', 'expense'])) {
                $running += (float) $a->opening_balance;
            } else {
                $running -= (float) $a->opening_balance;
            }
        }

        $rows = [];
        $perAccount = [];
        foreach ($accountIds as $aid) {
            $perAccount[$aid] = ['id' => $aid, 'code' => $accountsById->get($aid)->code, 'name' => $accountsById->get($aid)->name, 'debit' => 0.0, 'credit' => 0.0, 'opening' => (float) $accountsById->get($aid)->opening_balance, 'closing' => 0.0];
        }

        foreach ($lines as $line) {
            $signed = (float) $line->debit - (float) $line->credit;
            $running += $signed;
            $aid = (int) $line->account_id;
            if (isset($perAccount[$aid])) {
                $perAccount[$aid]['debit'] += (float) $line->debit;
                $perAccount[$aid]['credit'] += (float) $line->credit;
            }
            $rows[] = [
                'date' => $line->entry_date,
                'entry_number' => $line->entry_number,
                'reference' => $line->reference,
                'account_id' => $aid,
                'account_code' => $accountsById->get($aid)->code,
                'account_name' => $accountsById->get($aid)->name,
                'description' => $line->description ?: $line->entry_description,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'balance' => $running,
            ];
        }

        // closing per account = opening + signed sum (asset/expense: +; others: opening - net debit? )
        // Use same signed convention: closing = opening_signed + sum(debit-credit), where opening_signed
        // for credit-nature = -opening_balance. Then display closing as-is (signed). For credit-nature,
        // a positive signed means debit>credit which is unusual but consistent.
        foreach ($accountIds as $aid) {
            $a = $accountsById->get($aid);
            $signedOpening = in_array($a->type, ['asset', 'expense']) ? (float) $a->opening_balance : -(float) $a->opening_balance;
            $perAccount[$aid]['closing'] = $signedOpening + ($perAccount[$aid]['debit'] - $perAccount[$aid]['credit']);
        }

        $totalDebit = array_sum(array_column($rows, 'debit'));
        $totalCredit = array_sum(array_column($rows, 'credit'));

        return response()->json([
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type, 'opening_balance' => (float) $account->opening_balance],
            'include_children' => $includeChildren,
            'account_ids' => $accountIds,
            'opening_balance' => $running - ($totalDebit - $totalCredit), // best-effort
            'lines' => $rows,
            'closing_balance' => $running,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'per_account' => array_values($perAccount),
        ]);
    }

    public function incomeStatement(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->get('from');
        $to = $request->get('to');

        $revenues = Account::where('restaurant_id', $restaurantId)->where('type', 'revenue')->where('is_active', true)->orderBy('code')->get();
        $expenses = Account::where('restaurant_id', $restaurantId)->where('type', 'expense')->where('is_active', true)->orderBy('code')->get();

        $revRows = $revenues->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $this->balanceFor($a, $from, $to)]);
        $expRows = $expenses->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $this->balanceFor($a, $from, $to)]);

        $totalRevenue = (float) $revRows->sum('amount');
        $totalExpense = (float) $expRows->sum('amount');

        return response()->json([
            'revenue' => $revRows,
            'expense' => $expRows,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_income' => $totalRevenue - $totalExpense,
        ]);
    }

    public function balanceSheet(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $asOf = $request->get('as_of', now()->toDateString());

        $assets = Account::where('restaurant_id', $restaurantId)->where('type', 'asset')->where('is_active', true)->orderBy('code')->get();
        $liabilities = Account::where('restaurant_id', $restaurantId)->where('type', 'liability')->where('is_active', true)->orderBy('code')->get();
        $equity = Account::where('restaurant_id', $restaurantId)->where('type', 'equity')->where('is_active', true)->orderBy('code')->get();

        $assetRows = $assets->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $this->balanceFor($a, null, $asOf)]);
        $liabRows = $liabilities->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $this->balanceFor($a, null, $asOf)]);
        $equityRows = $equity->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $this->balanceFor($a, null, $asOf)]);

        $totalAssets = (float) $assetRows->sum('amount');
        $totalLiabilities = (float) $liabRows->sum('amount');
        $totalEquity = (float) $equityRows->sum('amount');

        return response()->json([
            'as_of' => $asOf,
            'assets' => $assetRows,
            'liabilities' => $liabRows,
            'equity' => $equityRows,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'total_liabilities_equity' => $totalLiabilities + $totalEquity,
        ]);
    }
}