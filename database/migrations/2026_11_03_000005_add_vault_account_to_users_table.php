<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('vault_account_id')->nullable()->after('avatar')->constrained('accounts')->onDelete('set null');
            $table->foreignId('pay_account_id')->nullable()->after('vault_account_id')->constrained('accounts')->onDelete('set null');
        });

        $cashierRoleIds = DB::table('roles')
            ->where('guard_name', 'sanctum')
            ->where(function ($query) {
                $query->where('name', 'like', '%cashier%')
                    ->orWhere('name', 'like', '%Cashier%')
                    ->orWhere('name', 'like', '%كاشير%');
            })
            ->pluck('id');

        $cashierUserIds = DB::table('model_has_roles')
            ->whereIn('role_id', $cashierRoleIds)
            ->where('model_type', User::class)
            ->distinct()
            ->pluck('model_id');

        foreach (User::whereIn('id', $cashierUserIds)->get() as $user) {
            $cashAccount = Account::firstOrCreate(
                ['restaurant_id' => $user->restaurant_id, 'code' => '1000'],
                ['name' => 'الخزنة الرئيسية', 'type' => 'asset', 'opening_balance' => 0, 'is_active' => true]
            );
            $expenseAccount = Account::firstOrCreate(
                ['restaurant_id' => $user->restaurant_id, 'code' => '5000'],
                ['name' => 'رواتب وأجور', 'type' => 'expense', 'opening_balance' => 0, 'is_active' => true]
            );
            $suffix = str_pad((string) $user->id, 3, '0', STR_PAD_LEFT);
            $vault = Account::firstOrCreate(
                ['restaurant_id' => $user->restaurant_id, 'code' => '11'.$suffix],
                [
                    'parent_id' => $cashAccount->id,
                    'name' => 'خزنة '.$user->name,
                    'type' => 'asset',
                    'opening_balance' => 0,
                    'is_active' => true,
                ]
            );
            $pay = Account::firstOrCreate(
                ['restaurant_id' => $user->restaurant_id, 'code' => '51'.$suffix],
                [
                    'parent_id' => $expenseAccount->id,
                    'name' => 'مصروف أجور '.$user->name,
                    'type' => 'expense',
                    'opening_balance' => 0,
                    'is_active' => true,
                ]
            );
            DB::table('users')->where('id', $user->id)->update([
                'vault_account_id' => $vault->id,
                'pay_account_id' => $pay->id,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['pay_account_id']);
            $table->dropForeign(['vault_account_id']);
            $table->dropColumn(['vault_account_id', 'pay_account_id']);
        });
    }
};
