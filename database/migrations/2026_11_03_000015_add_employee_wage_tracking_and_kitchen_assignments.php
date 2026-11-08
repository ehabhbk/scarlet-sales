<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_group_employees', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('shift_employee_payments', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('kitchen_station_id')
                ->nullable()
                ->after('category_id')
                ->constrained('kitchen_stations')
                ->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('kitchen_station_id')
                ->nullable()
                ->after('pay_account_id')
                ->constrained('kitchen_stations')
                ->nullOnDelete();
        });

        foreach ([
            'view_kitchen',
            'view_all_kitchen_orders',
            'view_station_kitchen_orders',
            'update_kitchen_order',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'sanctum']);
        }

        $rolePermissions = [
            'Chef' => ['view_kitchen', 'view_station_kitchen_orders', 'update_kitchen_order'],
            'Admin' => ['view_kitchen', 'view_all_kitchen_orders', 'view_station_kitchen_orders', 'update_kitchen_order'],
            'Super Admin' => ['view_kitchen', 'view_all_kitchen_orders', 'view_station_kitchen_orders', 'update_kitchen_order'],
        ];
        foreach ($rolePermissions as $roleName => $names) {
            $roleId = DB::table('roles')
                ->where('guard_name', 'sanctum')
                ->where('name', $roleName)
                ->value('id');
            $permissionIds = Permission::where('guard_name', 'sanctum')
                ->whereIn('name', $names)
                ->pluck('id');

            if ($roleId) {
                foreach ($permissionIds as $permissionId) {
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kitchen_station_id');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kitchen_station_id');
        });
        Schema::table('shift_employee_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn(['paid_at', 'paid_amount']);
        });
        Schema::table('shift_group_employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn(['paid_at', 'paid_amount']);
        });

        $permissionIds = Permission::where('guard_name', 'sanctum')
            ->whereIn('name', ['view_all_kitchen_orders', 'view_station_kitchen_orders'])
            ->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        Permission::whereIn('id', $permissionIds)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
