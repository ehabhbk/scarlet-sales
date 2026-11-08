<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'edit_order_payment',
            'guard_name' => 'sanctum',
        ]);

        // Admin and Super Admin already own every permission in the system.
        $allPermissionIds = Permission::where('guard_name', 'sanctum')->pluck('id');
        $adminRoleIds = DB::table('roles')
            ->where('guard_name', 'sanctum')
            ->whereIn('name', ['Admin', 'Super Admin'])
            ->pluck('id');

        foreach ($adminRoleIds as $roleId) {
            foreach ($allPermissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        // Managers and cashiers are the roles that actually settle order payments.
        $staffRoleIds = DB::table('roles')
            ->where('guard_name', 'sanctum')
            ->whereIn('name', ['Manager', 'Cashier'])
            ->pluck('id');

        foreach ($staffRoleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permission->id,
                'role_id' => $roleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = Permission::where('name', 'edit_order_payment')
            ->where('guard_name', 'sanctum')
            ->value('id');

        if ($permissionId) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            Permission::whereKey($permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
