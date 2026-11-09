<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use App\Models\KitchenStation;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\CashierAccountService;
use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $users = User::where('restaurant_id', $restaurantId)
            ->with(['roles', 'revenueAccount', 'payAccount', 'kitchenStation'])
            ->orderBy('name')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:100|unique:users,username',
            'password' => 'required|string',
            'phone' => 'nullable|string|max:50',
            'role' => 'required|string',
            'is_active' => 'boolean',
            'revenue_account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $request->user()->restaurant_id)
                    ->where('type', 'revenue')
                    ->whereNotNull('parent_id')
                    ->where('is_active', true)),
            ],
            'pay_account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $request->user()->restaurant_id)
                    ->where('type', 'expense')
                    ->where('is_active', true)),
            ],
            'kitchen_station_id' => [
                'required_if:role,Chef,chef',
                'nullable',
                'integer',
                Rule::exists('kitchen_stations', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $request->user()->restaurant_id)
                    ->where('is_active', true)),
            ],
            'image_file' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['password'] = Hash::make($validated['password']);
        $roleName = $validated['role'];
        unset($validated['role']);
        if ($request->hasFile('image_file')) {
            $validated['avatar'] = $request->file('image_file')->store('users', 'public');
        }
        unset($validated['image_file']);
        $user = DB::transaction(function () use ($validated, $roleName) {
            $user = User::create($validated);
            $role = Role::where('name', $roleName)->where('guard_name', 'sanctum')->first();
            if ($role) {
                $user->assignRole($role);
            }
            app(CashierAccountService::class)->ensureFor($user);

            return $user;
        });

        return response()->json([
            'user' => new UserResource($user->fresh()->load(['roles', 'revenueAccount', 'payAccount', 'kitchenStation'])),
            'message' => 'تم إضافة المستخدم',
        ], 201);
    }

    public function show(User $user)
    {
        if ($user->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        return response()->json(['user' => new UserResource($user->load(['roles', 'revenueAccount', 'payAccount', 'kitchenStation']))]);
    }

    public function update(Request $request, User $user)
    {
        if ($user->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'username' => 'sometimes|string|max:100|unique:users,username,'.$user->id,
            'password' => 'sometimes|string',
            'phone' => 'sometimes|nullable|string|max:50',
            'role' => 'sometimes|string',
            'is_active' => 'sometimes|boolean',
            'revenue_account_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $user->restaurant_id)
                    ->where('type', 'revenue')
                    ->whereNotNull('parent_id')
                    ->where('is_active', true)),
            ],
            'pay_account_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $user->restaurant_id)
                    ->where('type', 'expense')
                    ->where('is_active', true)),
            ],
            'kitchen_station_id' => [
                'sometimes',
                'required_if:role,Chef,chef',
                'nullable',
                'integer',
                Rule::exists('kitchen_stations', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $user->restaurant_id)
                    ->where('is_active', true)),
            ],
            'image_file' => 'sometimes|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);
        if ($request->has('password')) {
            $validated['password'] = Hash::make($validated['password']);
        }
        if ($request->hasFile('image_file')) {
            $oldAvatarPath = PublicMediaUrl::storagePath($user->avatar);
            if ($oldAvatarPath) {
                Storage::disk('public')->delete($oldAvatarPath);
            }
            $validated['avatar'] = $request->file('image_file')->store('users', 'public');
        }
        unset($validated['image_file']);
        $updateEmployeeAccount = array_key_exists('pay_account_id', $validated);
        $role = null;
        if ($request->has('role')) {
            $role = Role::where('name', $request->role)
                ->where('guard_name', 'sanctum')
                ->first();

            if (! $role) {
                throw ValidationException::withMessages([
                    'role' => ['الدور المحدد غير موجود.'],
                ]);
            }
        }

        DB::transaction(function () use ($user, $validated, $role, $updateEmployeeAccount) {
            $user->update($validated);
            if ($role) {
                $user->syncRoles([$role]);
            }
            $accountService = app(CashierAccountService::class);
            $accountService->ensureFor($user);
            if ($updateEmployeeAccount) {
                Employee::where('restaurant_id', $user->restaurant_id)
                    ->where('user_id', $user->id)
                    ->update(['pay_account_id' => $user->pay_account_id]);
            }
        });

        return response()->json([
            'user' => new UserResource($user->fresh()->load(['roles', 'revenueAccount', 'payAccount', 'kitchenStation'])),
            'message' => 'تم تحديث المستخدم',
        ]);
    }

    public function destroy(User $user)
    {
        if ($user->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $user->delete();

        return response()->json(['message' => 'تم حذف المستخدم']);
    }

    public function userWarehouses(Request $request, User $user)
    {
        if ($user->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        $warehouses = Warehouse::where('restaurant_id', $user->restaurant_id)
            ->orderBy('name')
            ->get()
            ->map(function ($w) use ($user) {
                $pivot = $user->userWarehouses()->where('warehouse_id', $w->id)->first();
                return [
                    'id' => $w->id,
                    'name' => $w->name,
                    'type' => $w->type,
                    'allow_sales' => (bool) $w->allow_sales,
                    'can_sell' => $pivot ? (bool) $pivot->can_sell : false,
                    'can_request_stock' => $pivot ? (bool) $pivot->can_request_stock : false,
                    'can_approve_stock' => $pivot ? (bool) $pivot->can_approve_stock : false,
                ];
            });

        return response()->json(['warehouses' => $warehouses]);
    }

    public function updateUserWarehouses(Request $request, User $user)
    {
        if ($user->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        $validated = $request->validate([
            'warehouses' => 'required|array',
            'warehouses.*.id' => 'required|integer|exists:warehouses,id',
            'warehouses.*.can_sell' => 'boolean',
            'warehouses.*.can_request_stock' => 'boolean',
            'warehouses.*.can_approve_stock' => 'boolean',
        ]);

        DB::transaction(function () use ($user, $validated) {
            // Remove existing
            UserWarehouse::where('user_id', $user->id)->delete();

            foreach ($validated['warehouses'] as $w) {
                $warehouse = Warehouse::where('restaurant_id', $user->restaurant_id)->find($w['id']);
                if (!$warehouse) continue;

                UserWarehouse::create([
                    'user_id' => $user->id,
                    'warehouse_id' => $warehouse->id,
                    'can_sell' => $w['can_sell'] ?? false,
                    'can_request_stock' => $w['can_request_stock'] ?? false,
                    'can_approve_stock' => $w['can_approve_stock'] ?? false,
                ]);
            }
        });

        return response()->json(['message' => 'تم تحديث مخازن المستخدم']);
    }
}

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $roles = Role::where('guard_name', 'sanctum')->with('permissions')->orderBy('name')->get();

        return response()->json(['roles' => $roles]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:roles,name',
            'permissions' => 'sometimes|array',
        ]);
        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'sanctum']);
        if (! empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json(['role' => $role->load('permissions'), 'message' => 'تم إضافة الدور'], 201);
    }

    public function show(Role $role)
    {
        return response()->json(['role' => $role->load('permissions')]);
    }

    public function update(Request $request, Role $role)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100|unique:roles,name,'.$role->id,
            'permissions' => 'sometimes|array',
        ]);
        $role->update($validated);
        if ($request->has('permissions')) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json(['role' => $role->fresh()->load('permissions'), 'message' => 'تم تحديث الدور']);
    }

    public function destroy(Role $role)
    {
        if (in_array($role->name, ['admin', 'Super Admin'])) {
            return response()->json(['message' => 'لا يمكن حذف الدور الأساسي'], 400);
        }
        $role->delete();

        return response()->json(['message' => 'تم حذف الدور']);
    }
}

class PermissionController extends Controller
{
    public function index(Request $request)
    {
        $catalog = config('permission_catalog');
        $permissionDetails = [];
        $sortOrder = 0;
        foreach ($catalog as $group => $entries) {
            foreach ($entries as $name => $label) {
                $permissionDetails[$name] = [
                    'group' => $group,
                    'label' => $label,
                    'sort_order' => $sortOrder++,
                ];
            }
        }

        $permissions = Permission::where('guard_name', 'sanctum')
            ->get()
            ->map(function (Permission $permission) use ($permissionDetails): array {
                $details = $permissionDetails[$permission->name] ?? [
                    'group' => 'صلاحيات إضافية',
                    'label' => 'صلاحية مخصصة',
                    'sort_order' => PHP_INT_MAX,
                ];

                return array_merge($permission->toArray(), $details);
            })
            ->sortBy([
                ['sort_order', 'asc'],
                ['name', 'asc'],
            ])
            ->values();

        return response()->json(['permissions' => $permissions]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:permissions,name',
        ]);
        $permission = Permission::create([
            'name' => $validated['name'],
            'guard_name' => 'sanctum',
        ]);

        return response()->json(['permission' => $permission, 'message' => 'تم إضافة الصلاحية'], 201);
    }

    public function destroy(Permission $permission)
    {
        $permission->delete();

        return response()->json(['message' => 'تم حذف الصلاحية']);
    }
}
