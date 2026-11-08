<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'revenue_account_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('revenue_account_id')
                    ->nullable()
                    ->after('pay_account_id')
                    ->constrained('accounts')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('shifts', 'wages_total')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->decimal('wages_total', 15, 4)->default(0)->after('difference');
            });
        }

        // Every POS user gets their own sales-revenue sub-account under 4000.
        $posPermissionsExist = Permission::where('guard_name', 'sanctum')
            ->whereIn('name', ['view_pos', 'create_orders'])
            ->count() === 2;

        if ($posPermissionsExist) {
            User::whereNotNull('restaurant_id')
                ->orderBy('id')
                ->chunkById(100, function ($users) {
                    foreach ($users as $user) {
                        try {
                            $worksPos = $user->hasPermissionTo('view_pos', 'sanctum')
                                && $user->hasPermissionTo('create_orders', 'sanctum');
                        } catch (\Throwable) {
                            $worksPos = false;
                        }

                        if (! $worksPos) {
                            continue;
                        }

                        $this->ensureRevenueAccount($user);
                    }
                });
        }

        // Retire the old per-cashier vault sub-accounts. Shift closings now post
        // directly against the configured payment-method accounts.
        $cashParentIds = Account::where('type', 'asset')
            ->where('code', '1000')
            ->pluck('id');

        $vaultIds = Account::where('type', 'asset')
            ->whereNotNull('parent_id')
            ->whereIn('parent_id', $cashParentIds)
            ->where('code', 'like', '11%')
            ->pluck('id');

        if ($vaultIds->isNotEmpty()) {
            DB::table('users')
                ->whereIn('vault_account_id', $vaultIds)
                ->update(['vault_account_id' => null]);
            Account::whereIn('id', $vaultIds)->delete();
        }
    }

    private function ensureRevenueAccount(User $user): void
    {
        $parent = Account::firstOrCreate(
            ['restaurant_id' => $user->restaurant_id, 'code' => '4000'],
            ['name' => 'إيرادات المبيعات', 'type' => 'revenue', 'opening_balance' => 0, 'is_active' => true]
        );

        if ($parent->type !== 'revenue' || ! $parent->is_active) {
            return;
        }

        $existing = $user->revenue_account_id
            ? Account::where('restaurant_id', $user->restaurant_id)
                ->whereKey($user->revenue_account_id)
                ->first()
            : null;

        if ($existing && $existing->type === 'revenue' && $existing->is_active) {
            return;
        }

        $account = Account::firstOrCreate(
            [
                'restaurant_id' => $user->restaurant_id,
                'code' => '41'.str_pad((string) $user->id, 3, '0', STR_PAD_LEFT),
            ],
            [
                'parent_id' => $parent->id,
                'name' => 'إيرادات '.$user->name,
                'type' => 'revenue',
                'opening_balance' => 0,
                'is_active' => true,
            ]
        );

        if ((int) $user->revenue_account_id !== (int) $account->id) {
            DB::table('users')->where('id', $user->id)->update(['revenue_account_id' => $account->id]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shifts', 'wages_total')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->dropColumn('wages_total');
            });
        }

        if (Schema::hasColumn('users', 'revenue_account_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['revenue_account_id']);
                $table->dropColumn('revenue_account_id');
            });
        }
    }
};
