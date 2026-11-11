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
        // Remove old restaurant-related permissions
        $oldPermissions = [
            'view_tables', 'create_tables', 'edit_tables', 'delete_tables',
            'view_kitchen', 'view_all_kitchen_orders', 'view_station_kitchen_orders', 'update_kitchen_order',
            'view_recipes', 'create_recipes', 'edit_recipes', 'delete_recipes',
            'manage_kitchen_products',
        ];

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'sanctum')
            ->whereIn('name', $oldPermissions)
            ->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        // Remove old roles
        DB::table('roles')->where('guard_name', 'sanctum')->whereIn('name', ['Chef', 'Waiter', 'chef', ' waiter'])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Add new roles: Storekeeper and Warehouse Supervisor
        $storekeeper = Role::firstOrCreate([
            'name' => 'Storekeeper',
            'guard_name' => 'sanctum',
        ]);
        $storekeeperPermissions = [
            'view_warehouses', 'view_products', 'view_inventory', 'create_stock_requests', 'view_stock_requests',
            'create_orders', 'view_pos', 'view_orders',
        ];
        foreach ($storekeeperPermissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'sanctum']);
        }
        $storekeeper->syncPermissions($storekeeperPermissions);

        $warehouseSupervisor = Role::firstOrCreate([
            'name' => 'Warehouse Supervisor',
            'guard_name' => 'sanctum',
        ]);
        $warehouseSupervisorPermissions = [
            'view_warehouses', 'create_warehouses', 'edit_warehouses', 'delete_warehouses', 'manage_stock',
            'view_products', 'view_inventory', 'create_stock_requests', 'view_stock_requests', 'approve_stock_requests', 'reject_stock_requests',
            'view_orders', 'create_orders', 'view_pos',
        ];
        foreach ($warehouseSupervisorPermissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'sanctum']);
        }
        $warehouseSupervisor->syncPermissions($warehouseSupervisorPermissions);
    }

    public function down(): void
    {
        // Remove new roles
        DB::table('roles')->where('guard_name', 'sanctum')->whereIn('name', ['Storekeeper', 'Warehouse Supervisor'])->delete();
    }
};