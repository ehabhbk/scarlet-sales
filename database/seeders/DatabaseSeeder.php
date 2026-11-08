<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * This seeder is intentionally limited to the data a fresh installation
     * needs: the restaurant record, every permission, the five base roles and
     * a single admin account that owns all permissions.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $restaurant = Restaurant::updateOrCreate(
            ['slug' => 'scarlet-restaurant'],
            [
                'name' => 'scarlet restaurant',
                'phone' => '+966500000000',
                'email' => 'info@scarletresta.com',
                'address' => 'الرياض، المملكة العربية السعودية',
                'currency' => 'SAR',
                'symbol' => 'ر.س',
                'tax_number' => '300000000000000',
                'tax_rate' => 0.15,
                'timezone' => 'Asia/Riyadh',
                'locale' => 'ar',
                'primary_color' => '#0f172a',
                'is_active' => true,
            ]
        );

        // 1. Permissions — sourced from the same catalog used by the UI.
        $permissionNames = [];
        foreach (config('permission_catalog', []) as $group) {
            foreach (array_keys($group) as $permissionName) {
                $permissionNames[] = $permissionName;
            }
        }
        $permissionNames = array_values(array_unique($permissionNames));

        foreach ($permissionNames as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'sanctum',
            ]);
        }

        $permissions = Permission::where('guard_name', 'sanctum')
            ->whereIn('name', $permissionNames)
            ->get();

        // 2. Base roles.
        $rolePermissions = [
            'admin' => $permissionNames,
            'chef' => [
                'view_dashboard',
                'view_products',
                'view_kitchen',
                'view_all_kitchen_orders',
                'view_station_kitchen_orders',
                'update_kitchen_order',
            ],
            'Cashier' => [
                'view_dashboard',
                'view_pos',
                'view_orders',
                'create_orders',
                'edit_orders',
                'edit_order_payment',
                'view_products',
                'view_categories',
                'view_tables',
                'view_customers',
                'create_customers',
                'view_finance',
                'view_payments',
                'open_shift',
                'close_shift',
                'view_notifications',
            ],
            'Accountant' => [
                'view_dashboard',
                'view_finance',
                'view_payments',
                'create_expenses',
                'edit_expenses',
                'delete_expenses',
                'create_payments',
                'edit_payments',
                'delete_payments',
                'view_reports',
                'export_reports',
                'view_purchases',
                'view_suppliers',
                'view_all_shifts',
                'open_shift',
                'close_shift',
                'pay_employee_wages',
                'refund_orders',
                'view_notifications',
            ],
            'waiter' => [
                'view_dashboard',
                'view_orders',
                'create_orders',
                'edit_orders',
                'view_products',
                'view_categories',
                'view_tables',
                'view_customers',
                'create_customers',
                'view_kitchen',
                'view_notifications',
            ],
        ];

        // MySQL compares role names case-insensitively, so legacy roles such as
        // "Admin", "Chef" or "Waiter" would otherwise be reused instead of
        // being normalised to the canonical lower-case names. Rename them in
        // PHP so the stored value matches exactly what the UI expects.
        $existingRoles = Role::where('guard_name', 'sanctum')->get();

        $roles = [];
        foreach ($rolePermissions as $roleName => $names) {
            $role = $existingRoles->first(fn (Role $item) => $item->name === $roleName)
                ?? $existingRoles->first(
                    fn (Role $item) => mb_strtolower($item->name) === mb_strtolower($roleName)
                );

            if (! $role) {
                $role = Role::create([
                    'name' => $roleName,
                    'guard_name' => 'sanctum',
                ]);
                $existingRoles->push($role);
            } elseif ($role->name !== $roleName) {
                $role->forceFill(['name' => $roleName])->save();
            }

            $roles[$roleName] = $role;

            $role->syncPermissions(
                $permissions->whereIn('name', $names)->values()
            );
        }

        // 3. Admin account with all permissions.
        $admin = User::where('username', 'admin')
            ->orWhere('email', 'admin@scarletresta.com')
            ->first();

        $attributes = [
            'name' => 'مدير النظام',
            'username' => 'admin',
            'email' => 'admin@scarletresta.com',
            'password' => Hash::make('admin'),
            'restaurant_id' => $restaurant->id,
            'phone' => '+966500000001',
            'is_active' => true,
        ];

        if ($admin) {
            $admin->update($attributes);
        } else {
            $admin = User::create($attributes);
        }

        $admin->syncRoles([$roles['admin']]);
        $admin->syncPermissions($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Database seeded: permissions, roles (admin, chef, Cashier, Accountant, waiter) and the admin user are ready.');
    }
}
