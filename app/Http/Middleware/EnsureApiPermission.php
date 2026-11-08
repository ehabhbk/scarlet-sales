<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class EnsureApiPermission
{
    public function handle(Request $request, Closure $next, string $resource)
    {
        if ($request->user()->hasAnyRole(['admin', 'Super Admin', 'Admin'], 'sanctum')) {
            return $next($request);
        }

        $action = $this->permissionFor($resource, $request);
        $allowed = is_array($action)
            ? collect($action)->contains(fn (string $permission) => $this->userHasPermission($request, $permission))
            : ($action && $this->userHasPermission($request, $action));
        abort_unless($allowed, 403);

        if ($resource === 'reports' && str_contains($request->path(), '/reports/export/')) {
            $type = basename($request->path());
            $reportPermission = $this->reportPermission($type);
            $canViewReport = is_array($reportPermission)
                ? collect($reportPermission)->contains(fn (string $permission) => $this->userHasPermission($request, $permission))
                : ($reportPermission && $this->userHasPermission($request, $reportPermission));
            abort_unless($canViewReport, 403);
        }

        return $next($request);
    }

    private function permissionFor(string $resource, Request $request): string|array|null
    {
        $method = $request->method();
        $read = in_array($method, ['GET', 'HEAD'], true);

        if ($resource === 'reports') {
            if (str_contains($request->path(), '/reports/export/')) {
                return 'export_reports';
            }

            return $this->reportPermission(basename($request->path()));
        }

        if ($resource === 'kitchen') {
            return $read
                ? ['view_kitchen', 'view_all_kitchen_orders', 'view_station_kitchen_orders']
                : 'update_kitchen_order';
        }

        if ($resource === 'attendances') {
            return $read ? 'view_employees' : 'edit_employees';
        }

        if ($resource === 'accounts' || $resource === 'journal-entries') {
            return $read
                ? ($resource === 'accounts'
                    ? [
                        'view_finance',
                        'edit_settings',
                        'view_users',
                        'create_users',
                        'edit_users',
                        'view_purchases',
                        'create_purchases',
                        'view_employees',
                        'create_employees',
                        'edit_employees',
                    ]
                    : 'view_finance')
                : 'manage_chart_of_accounts';
        }

        if ($resource === 'settings') {
            return $read ? 'view_settings' : 'edit_settings';
        }

        if ($resource === 'notifications') {
            return 'view_notifications';
        }

        if ($resource === 'warehouses') {
            if ($read) {
                return ['view_warehouses', 'view_products', 'view_inventory', 'create_orders', 'view_pos'];
            }

            if ($method === 'DELETE') {
                return 'delete_warehouses';
            }

            return $method === 'POST' ? 'create_warehouses' : 'edit_warehouses';
        }

        if ($resource === 'warehouse-stock') {
            return $read ? ['view_warehouses', 'view_products', 'view_inventory'] : 'manage_stock';
        }

        if ($resource === 'dashboard') {
            return 'view_dashboard';
        }

        $prefix = [
            'products' => 'products',
            'categories' => 'categories',
            'tables' => 'tables',
            'areas' => 'tables',
            'inventory-items' => 'inventory',
            'inventory-units' => 'inventory',
            'stock-movements' => 'inventory',
            'purchases' => 'purchases',
            'suppliers' => 'suppliers',
            'recipes' => 'recipes',
            'customers' => 'customers',
            'employees' => 'employees',
            'expenses' => 'expenses',
            'payments' => 'payments',
            'users' => 'users',
            'roles' => 'roles',
            'permissions' => 'roles',
        ][$resource] ?? rtrim($resource, 's');

        if ($read) {
            if ($resource === 'inventory-items') {
                return ['view_inventory', 'view_purchases', 'create_purchases'];
            }
            if (in_array($resource, ['products', 'categories', 'tables', 'areas'], true)) {
                $viewPermission = match ($resource) {
                    'products' => 'view_products',
                    'categories' => 'view_categories',
                    default => 'view_tables',
                };

                return [$viewPermission, 'create_orders', 'view_pos'];
            }

            return match ($prefix) {
                'expenses' => 'view_finance',
                'payments' => 'view_payments',
                'suppliers' => ['view_suppliers', 'view_purchases', 'create_purchases'],
                default => 'view_'.$prefix,
            };
        }

        if (in_array($method, ['DELETE'], true)) {
            return 'delete_'.$prefix;
        }

        return in_array($method, ['POST'], true)
            ? 'create_'.$prefix
            : 'edit_'.$prefix;
    }

    private function reportPermission(string $type): string|array|null
    {
        return match ($type) {
            'profit-loss', 'expenses' => 'view_finance',
            'inventory' => 'view_inventory',
            'purchases' => 'view_purchases',
            'staff' => 'view_employees',
            'shifts' => ['view_all_shifts', 'close_shift'],
            default => 'view_reports',
        };
    }

    private function userHasPermission(Request $request, string $permission): bool
    {
        return Permission::where('name', $permission)
            ->where('guard_name', 'sanctum')
            ->exists()
            && $request->user()->hasPermissionTo($permission, 'sanctum');
    }
}
