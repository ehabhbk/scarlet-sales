<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate([
            'name' => 'view_pos',
            'guard_name' => 'sanctum',
        ]);

        $permissionIds = Permission::where('guard_name', 'sanctum')->pluck('id');
        $adminRoleIds = DB::table('roles')
            ->where('guard_name', 'sanctum')
            ->whereIn('name', ['Admin', 'Super Admin'])
            ->pluck('id');

        foreach ($adminRoleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        foreach (['Manager', 'Cashier'] as $roleName) {
            $roleId = DB::table('roles')
                ->where('guard_name', 'sanctum')
                ->where('name', $roleName)
                ->value('id');
            $permissionId = Permission::where('name', 'view_pos')
                ->where('guard_name', 'sanctum')
                ->value('id');

            if ($roleId && $permissionId) {
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
        $permissionId = Permission::where('name', 'view_pos')
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
