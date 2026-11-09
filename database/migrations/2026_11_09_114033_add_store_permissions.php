<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'view_stores' => 'عرض المتاجر',
            'create_stores' => 'إضافة متجر',
            'edit_stores' => 'تعديل متجر',
            'delete_stores' => 'حذف متجر',
            'request_stock_for_store' => 'طلب من المخزن للمتجر',
        ];

        foreach ($permissions as $name => $label) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'sanctum']);
        }

        // Give these permissions to admin roles
        $roleIds = DB::table('roles')
            ->where('guard_name', 'sanctum')
            ->whereIn('name', ['admin', 'Admin', 'Super Admin', 'Warehouse Supervisor'])
            ->pluck('id');

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'sanctum')
            ->whereIn('name', array_keys($permissions))
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = ['view_stores', 'create_stores', 'edit_stores', 'delete_stores', 'request_stock_for_store'];
        $ids = DB::table('permissions')->where('guard_name', 'sanctum')->whereIn('name', $names)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
