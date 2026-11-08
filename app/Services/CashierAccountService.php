<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class CashierAccountService
{
    public function ensurePayAccountFor(User $user): Account
    {
        $restaurantId = $user->restaurant_id;
        $suffix = str_pad((string) $user->id, 3, '0', STR_PAD_LEFT);
        $pay = $user->pay_account_id
            ? Account::where('restaurant_id', $restaurantId)->whereKey($user->pay_account_id)->first()
            : null;
        if ($user->pay_account_id && (! $pay || $pay->type !== 'expense' || ! $pay->is_active)) {
            throw ValidationException::withMessages([
                'pay_account_id' => ['حساب مصروف الأجر المحدد غير صالح أو غير نشط.'],
            ]);
        }
        if (! $pay) {
            $expenseAccount = Account::firstOrCreate(
                ['restaurant_id' => $restaurantId, 'code' => '5000'],
                ['name' => 'رواتب وأجور', 'type' => 'expense', 'opening_balance' => 0, 'is_active' => true]
            );
            if ($expenseAccount->type !== 'expense' || ! $expenseAccount->is_active) {
                throw ValidationException::withMessages([
                    'pay_account_id' => ['حساب الرواتب الرئيسي غير صالح أو غير نشط.'],
                ]);
            }
            $pay = Account::firstOrCreate(
                ['restaurant_id' => $restaurantId, 'code' => '51'.$suffix],
                [
                    'parent_id' => $expenseAccount->id,
                    'name' => 'مصروف أجور '.$user->name,
                    'type' => 'expense',
                    'opening_balance' => 0,
                    'is_active' => true,
                ]
            );
        }

        if ($pay->type !== 'expense' || ! $pay->is_active) {
            throw ValidationException::withMessages([
                'pay_account_id' => ['حساب مصروف الأجر غير صالح أو غير نشط.'],
            ]);
        }

        if ((int) $user->pay_account_id !== (int) $pay->id) {
            $user->forceFill(['pay_account_id' => $pay->id])->save();
        }

        return $pay;
    }

    /**
     * Ensure the user owns a sales-revenue sub-account under 4000.
     *
     * This is used at shift closing for every cashier even when their role no
     * longer carries the POS permissions, so the closing entry always resolves
     * to a concrete revenue account.
     */
    public function ensureRevenueAccountFor(User $user): Account
    {
        $restaurantId = $user->restaurant_id;
        $parent = Account::withTrashed()
            ->where('restaurant_id', $restaurantId)
            ->where('code', '4000')
            ->first();
        if (! $parent) {
            $parent = Account::create([
                'restaurant_id' => $restaurantId,
                'code' => '4000',
                'name' => 'إيرادات المبيعات',
                'type' => 'revenue',
                'opening_balance' => 0,
                'is_active' => true,
            ]);
        } elseif ($parent->trashed()) {
            $parent->restore();
            $parent->is_active = true;
            $parent->save();
        }

        if ($parent->type !== 'revenue' || ! $parent->is_active) {
            throw ValidationException::withMessages([
                'revenue_account_id' => ['حساب إيرادات المبيعات الرئيسي غير صالح أو غير نشط.'],
            ]);
        }

        $revenue = $user->revenue_account_id
            ? Account::where('restaurant_id', $restaurantId)->whereKey($user->revenue_account_id)->first()
            : null;

        if ($user->revenue_account_id && (! $revenue || $revenue->type !== 'revenue' || ! $revenue->is_active)) {
            throw ValidationException::withMessages([
                'revenue_account_id' => ['حساب إيراد المستخدم المحدد غير صالح أو غير نشط.'],
            ]);
        }

        if (! $revenue) {
            $suffix = str_pad((string) $user->id, 3, '0', STR_PAD_LEFT);
            $revenue = Account::firstOrCreate(
                ['restaurant_id' => $restaurantId, 'code' => '41'.$suffix],
                [
                    'parent_id' => $parent->id,
                    'name' => 'إيرادات '.$user->name,
                    'type' => 'revenue',
                    'opening_balance' => 0,
                    'is_active' => true,
                ]
            );
        }

        if ($revenue->type !== 'revenue' || ! $revenue->is_active) {
            throw ValidationException::withMessages([
                'revenue_account_id' => ['حساب إيراد المستخدم غير صالح أو غير نشط.'],
            ]);
        }

        if ((int) $user->revenue_account_id !== (int) $revenue->id) {
            $user->forceFill(['revenue_account_id' => $revenue->id])->save();
        }

        return $revenue;
    }

    /**
     * A user is a POS cashier when they can both open the POS screen and
     * create orders. Only these users need a personal revenue account.
     */
    public function userWorksPos(User $user): bool
    {
        foreach (['view_pos', 'create_orders'] as $permission) {
            if (! Permission::where('name', $permission)
                ->where('guard_name', 'sanctum')
                ->exists()) {
                return false;
            }
        }

        try {
            return $user->hasPermissionTo('view_pos', 'sanctum')
                && $user->hasPermissionTo('create_orders', 'sanctum');
        } catch (\Throwable) {
            return false;
        }
    }

    public function ensureFor(User $user): void
    {
        $this->ensurePayAccountFor($user);

        if ($this->userWorksPos($user)) {
            $this->ensureRevenueAccountFor($user);
        }
    }

    public function isCashierRole(string $roleName): bool
    {
        return stripos($roleName, 'cashier') !== false || str_contains($roleName, 'كاشير');
    }
}
