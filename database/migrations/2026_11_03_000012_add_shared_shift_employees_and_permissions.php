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
        Schema::table('shift_groups', function (Blueprint $table) {
            $table->string('status', 20)->default('open')->after('shift_date');
        });
        DB::table('shift_groups')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('shifts')
                    ->whereColumn('shifts.shift_group_id', 'shift_groups.id')
                    ->where('shifts.status', 'open')
                    ->whereNull('shifts.deleted_at');
            })
            ->update(['status' => 'closed']);

        Schema::create('shift_group_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('payment_method', 50)->nullable();
            $table->timestamps();
            $table->unique(['shift_group_id', 'employee_id']);
        });

        DB::table('shift_employee_payments')
            ->join('shifts', 'shifts.id', '=', 'shift_employee_payments.shift_id')
            ->whereNotNull('shifts.shift_group_id')
            ->select([
                'shifts.shift_group_id',
                'shift_employee_payments.employee_id',
                'shift_employee_payments.payment_method',
            ])
            ->orderBy('shifts.shift_group_id')
            ->chunk(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('shift_group_employees')->insertOrIgnore([
                        'shift_group_id' => $row->shift_group_id,
                        'employee_id' => $row->employee_id,
                        'payment_method' => $row->payment_method,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

        $permissions = [
            'delete_purchases',
            'delete_recipes',
            'delete_customers',
            'delete_employees',
            'delete_suppliers',
            'manage_chart_of_accounts',
            'create_payments',
            'edit_payments',
            'delete_payments',
            'view_notifications',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'sanctum']);
        }

        $allPermissionIds = Permission::where('guard_name', 'sanctum')->pluck('id');
        $adminRoles = DB::table('roles')
            ->where('guard_name', 'sanctum')
            ->whereIn('name', ['Admin', 'Super Admin'])
            ->pluck('id');
        foreach ($adminRoles as $roleId) {
            foreach ($allPermissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_group_employees');
        Schema::table('shift_groups', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'sanctum')
            ->whereIn('name', [
                'delete_purchases',
                'delete_recipes',
                'delete_customers',
                'delete_employees',
                'delete_suppliers',
                'manage_chart_of_accounts',
                'create_payments',
                'edit_payments',
                'delete_payments',
                'view_notifications',
            ])
            ->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
