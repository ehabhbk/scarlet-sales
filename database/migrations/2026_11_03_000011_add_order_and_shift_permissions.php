<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'view_orders',
            'view_all_orders',
            'create_orders',
            'edit_orders',
            'delete_orders',
            'cancel_orders',
            'refund_orders',
            'open_shift',
            'close_shift',
            'manage_multi_cashier_shifts',
            'manage_shift_employees',
            'pay_employee_wages',
            'view_all_shifts',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'sanctum']);
        }

        $defaults = [
            'Super Admin' => $permissions,
            'Admin' => $permissions,
            'Manager' => $permissions,
            'Cashier' => ['view_orders', 'create_orders', 'edit_orders', 'open_shift', 'close_shift'],
            'Waiter' => ['view_orders', 'create_orders', 'edit_orders'],
            'Accountant' => ['view_all_shifts', 'pay_employee_wages', 'refund_orders'],
        ];

        foreach ($defaults as $roleName => $rolePermissions) {
            $role = DB::table('roles')
                ->where('name', $roleName)
                ->where('guard_name', 'sanctum')
                ->first();
            if (! $role) {
                continue;
            }

            $permissionIds = Permission::where('guard_name', 'sanctum')
                ->whereIn('name', $rolePermissions)
                ->pluck('id');
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $role->id,
                ]);
            }
        }

        $viewOrdersId = DB::table('permissions')
            ->where('guard_name', 'sanctum')
            ->where('name', 'view_orders')
            ->value('id');
        if ($viewOrdersId) {
            $roleIds = DB::table('roles')
                ->where('guard_name', 'sanctum')
                ->whereIn('name', ['Cashier', 'Waiter'])
                ->pluck('id');
            foreach ($roleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $viewOrdersId,
                    'role_id' => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'sanctum')
            ->whereIn('name', [
                'view_all_orders',
                'refund_orders',
                'open_shift',
                'close_shift',
                'manage_multi_cashier_shifts',
                'manage_shift_employees',
                'pay_employee_wages',
                'view_all_shifts',
            ])
            ->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
