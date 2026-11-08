<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $accounts = Account::where('restaurant_id', $restaurantId)
            ->with('parent')
            ->orderBy('code')
            ->get();
        return response()->json(['accounts' => $accounts]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|exists:accounts,id',
            'code' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'opening_balance' => 'nullable|numeric',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['opening_balance'] = $validated['opening_balance'] ?? 0;
        $validated['is_active'] = $request->boolean('is_active', true);
        $account = Account::create($validated);
        return response()->json(['account' => $account->load('parent'), 'message' => 'تم إنشاء الحساب'], 201);
    }

    public function show(Account $account)
    {
        if ($account->restaurant_id !== auth()->user()->restaurant_id) abort(403);
        return response()->json(['account' => $account->load('parent', 'children')]);
    }

    public function update(Request $request, Account $account)
    {
        if ($account->restaurant_id !== auth()->user()->restaurant_id) abort(403);
        $validated = $request->validate([
            'parent_id' => 'nullable|exists:accounts,id',
            'code' => 'sometimes|string|max:50',
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:asset,liability,equity,revenue,expense',
            'opening_balance' => 'sometimes|numeric',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string',
        ]);
        $account->update($validated);
        return response()->json(['account' => $account->fresh()->load('parent'), 'message' => 'تم تحديث الحساب']);
    }

    public function destroy(Account $account)
    {
        if ($account->restaurant_id !== auth()->user()->restaurant_id) abort(403);
        if ($account->lines()->exists() || $account->children()->exists()) {
            return response()->json(['message' => 'لا يمكن حذف حساب له حركات أو حسابات فرعية'], 400);
        }
        $account->delete();
        return response()->json(['message' => 'تم حذف الحساب']);
    }
}