<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Create a default warehouse for every restaurant and move the legacy
        // product stock into it so the POS keeps working right after deploy.
        $restaurants = DB::table('restaurants')->pluck('id');

        foreach ($restaurants as $restaurantId) {
            $warehouseId = DB::table('warehouses')
                ->where('restaurant_id', $restaurantId)
                ->where('code', 'MAIN')
                ->value('id');

            if (! $warehouseId) {
                $warehouseId = DB::table('warehouses')->insertGetId([
                    'restaurant_id' => $restaurantId,
                    'name' => 'المخزن الرئيسي',
                    'code' => 'MAIN',
                    'type' => 'store',
                    'is_default' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $products = DB::table('products')
                ->where('restaurant_id', $restaurantId)
                ->whereNull('deleted_at')
                ->where('current_stock', '>', 0)
                ->get(['id', 'current_stock', 'cost_price']);

            foreach ($products as $product) {
                $hasBatch = DB::table('product_batches')
                    ->where('restaurant_id', $restaurantId)
                    ->where('product_id', $product->id)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($hasBatch) {
                    continue;
                }

                DB::table('product_batches')->insert([
                    'restaurant_id' => $restaurantId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $product->id,
                    'batch_number' => 'OPENING',
                    'quantity' => $product->current_stock,
                    'cost_price' => $product->cost_price ?? 0,
                    'received_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Permissions for the new warehouse / stock module.
        $permissions = [
            'view_warehouses' => 'عرض المخازن',
            'create_warehouses' => 'إضافة مخزن',
            'edit_warehouses' => 'تعديل مخزن',
            'delete_warehouses' => 'حذف مخزن',
            'manage_stock' => 'إدارة أرصدة المخازن',
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
        $names = [
            'view_warehouses',
            'create_warehouses',
            'edit_warehouses',
            'delete_warehouses',
            'manage_stock',
        ];

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'sanctum')
            ->whereIn('name', $names)
            ->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
