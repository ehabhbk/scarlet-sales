<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\CashierAccountService;
use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $login = $request->username;
        $user = User::where('username', $login)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'بيانات تسجيل الدخول غير صحيحة'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'الحساب معطل، راجع المشرف'], 403);
        }

        try {
            app(CashierAccountService::class)->ensureFor($user);
        } catch (\Throwable) {
            // Account provisioning must never block authentication.
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user->load('restaurant', 'roles', 'revenueAccount', 'payAccount')),
            'token' => $token,
            'message' => 'تم تسجيل الدخول بنجاح',
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:100|unique:users,username',
            'password' => 'required|string|confirmed',
            'restaurant_id' => 'required|exists:restaurants,id',
            'phone' => 'nullable|string|max:20',
        ]);

        $roleName = (string) ($request->role ?? 'Cashier');
        $user = DB::transaction(function () use ($request, $roleName) {
            $user = User::create([
                'name' => $request->name,
                'username' => $request->username,
                'password' => $request->password,
                'restaurant_id' => $request->restaurant_id,
                'phone' => $request->phone,
                'is_active' => true,
            ]);
            $role = Role::where('name', $roleName)
                ->where('guard_name', 'sanctum')
                ->firstOrFail();
            $user->assignRole($role);
            $accountService = app(CashierAccountService::class);
            $accountService->ensurePayAccountFor($user);
            if ($accountService->isCashierRole($role->name)) {
                $accountService->ensureFor($user);
            }

            return $user;
        });

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user->load('restaurant', 'roles', 'revenueAccount', 'payAccount')),
            'token' => $token,
            'message' => 'تم إنشاء الحساب بنجاح',
        ], 201);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج بنجاح']);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => new UserResource($request->user()->load('restaurant', 'roles', 'revenueAccount', 'payAccount')),
        ]);
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);
        $user = $request->user();
        $oldAvatarPath = PublicMediaUrl::storagePath($user->avatar);
        if ($oldAvatarPath) {
            Storage::disk('public')->delete($oldAvatarPath);
        }
        $user->update([
            'avatar' => $request->file('image')->store('users', 'public'),
        ]);

        return response()->json([
            'user' => new UserResource($user->fresh()->load('restaurant', 'roles', 'revenueAccount', 'payAccount')),
            'message' => 'تم تحديث الصورة الشخصية',
        ]);
    }

    public function restaurant(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        if (! $restaurant) {
            return response()->json(['message' => 'لا يوجد مطعم مرتبط بالمستخدم'], 404);
        }
        $data = $restaurant->toArray();
        $data['logo'] = PublicMediaUrl::make($restaurant->logo);

        return response()->json(['restaurant' => $data]);
    }
}
