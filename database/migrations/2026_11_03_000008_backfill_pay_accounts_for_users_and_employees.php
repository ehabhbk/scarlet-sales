<?php

use App\Models\Account;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'pay_account_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('pay_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('employees', 'pay_account_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->foreignId('pay_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            });
        }

        User::whereNotNull('restaurant_id')
            ->whereNull('pay_account_id')
            ->orderBy('id')
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    $parent = Account::firstOrCreate(
                        ['restaurant_id' => $user->restaurant_id, 'code' => '5000'],
                        ['name' => 'رواتب وأجور', 'type' => 'expense', 'opening_balance' => 0, 'is_active' => true]
                    );
                    if ($parent->type !== 'expense' || ! $parent->is_active) {
                        throw new RuntimeException('Cannot backfill user pay accounts: the default wage account is invalid.');
                    }

                    $account = Account::firstOrCreate(
                        [
                            'restaurant_id' => $user->restaurant_id,
                            'code' => '51'.str_pad((string) $user->id, 3, '0', STR_PAD_LEFT),
                        ],
                        [
                            'parent_id' => $parent->id,
                            'name' => 'مصروف أجور '.$user->name,
                            'type' => 'expense',
                            'opening_balance' => 0,
                            'is_active' => true,
                        ]
                    );
                    if ($account->type !== 'expense' || ! $account->is_active) {
                        throw new RuntimeException('Cannot backfill user pay accounts: a generated account is invalid.');
                    }

                    DB::table('users')->where('id', $user->id)->update(['pay_account_id' => $account->id]);
                }
            });

        Employee::whereNull('pay_account_id')
            ->orderBy('id')
            ->chunkById(100, function ($employees) {
                foreach ($employees as $employee) {
                    $userAccountId = $employee->user_id
                        ? User::whereKey($employee->user_id)
                            ->where('restaurant_id', $employee->restaurant_id)
                            ->value('pay_account_id')
                        : null;
                    if ($userAccountId) {
                        DB::table('employees')->where('id', $employee->id)->update(['pay_account_id' => $userAccountId]);

                        continue;
                    }

                    $parent = Account::firstOrCreate(
                        ['restaurant_id' => $employee->restaurant_id, 'code' => '5000'],
                        ['name' => 'رواتب وأجور', 'type' => 'expense', 'opening_balance' => 0, 'is_active' => true]
                    );
                    if ($parent->type !== 'expense' || ! $parent->is_active) {
                        throw new RuntimeException('Cannot backfill employee pay accounts: the default wage account is invalid.');
                    }

                    $account = Account::firstOrCreate(
                        [
                            'restaurant_id' => $employee->restaurant_id,
                            'code' => '52'.str_pad((string) $employee->id, 6, '0', STR_PAD_LEFT),
                        ],
                        [
                            'parent_id' => $parent->id,
                            'name' => 'مصروف أجور '.$employee->name,
                            'type' => 'expense',
                            'opening_balance' => 0,
                            'is_active' => true,
                        ]
                    );
                    if ($account->type !== 'expense' || ! $account->is_active) {
                        throw new RuntimeException('Cannot backfill employee pay accounts: a generated account is invalid.');
                    }

                    DB::table('employees')->where('id', $employee->id)->update(['pay_account_id' => $account->id]);
                }
            });
    }

    public function down(): void
    {
        // Account links are user data and are intentionally retained on rollback.
    }
};
