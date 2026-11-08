<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissions = [
            'view_stock_requests' => 'عرض طلبات المخزون',
            'create_stock_requests' => 'إنشاء طلب مخزون',
            'approve_stock_requests' => 'الموافقة على طلب مخزون',
            'reject_stock_requests' => 'رفض طلب مخزون',
        ];

        foreach ($permissions as $name => $label) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'sanctum'],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'sanctum')
            ->whereIn('name', array_keys($permissions))
            ->pluck('id');

        $roleIds = DB::table('roles')
            ->whereIn('name', ['admin', 'Admin', 'Super Admin'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $names = ['view_stock_requests', 'create_stock_requests', 'approve_stock_requests', 'reject_stock_requests'];
        $ids = DB::table('permissions')->where('guard_name', 'sanctum')->whereIn('name', $names)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};