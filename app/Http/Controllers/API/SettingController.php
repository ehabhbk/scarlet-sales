<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Notification;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $settings = Setting::where('restaurant_id', $restaurantId)
            ->orderBy('group')
            ->orderBy('key')
            ->get();

        return response()->json(['settings' => $settings]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'group' => 'required|string|max:50',
            'key' => 'required|string|max:100',
            'value' => 'nullable|string',
            'type' => 'sometimes|in:string,number,boolean,json',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['type'] = $validated['type'] ?? 'string';
        $this->validatePaymentMethods($validated, $validated['restaurant_id']);
        $setting = Setting::updateOrCreate(
            ['restaurant_id' => $validated['restaurant_id'], 'group' => $validated['group'], 'key' => $validated['key']],
            ['value' => $validated['value'], 'type' => $validated['type']]
        );

        return response()->json(['setting' => $setting, 'message' => 'تم حفظ الإعداد'], 201);
    }

    public function show(Setting $setting)
    {
        if ($setting->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        return response()->json(['setting' => $setting]);
    }

    public function update(Request $request, Setting $setting)
    {
        if ($setting->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'value' => 'sometimes|string',
            'type' => 'sometimes|in:string,number,boolean,json',
        ]);
        if ($setting->group === 'pos' && $setting->key === 'payment_methods' && array_key_exists('value', $validated)) {
            $this->validatePaymentMethods([
                'group' => $setting->group,
                'key' => $setting->key,
                'value' => $validated['value'],
            ], $setting->restaurant_id);
        }
        $setting->update($validated);

        return response()->json(['setting' => $setting->fresh(), 'message' => 'تم تحديث الإعداد']);
    }

    public function destroy(Setting $setting)
    {
        if ($setting->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $setting->delete();

        return response()->json(['message' => 'تم حذف الإعداد']);
    }

    private function validatePaymentMethods(array $setting, int $restaurantId): void
    {
        if (($setting['group'] ?? null) !== 'pos' || ($setting['key'] ?? null) !== 'payment_methods') {
            return;
        }

        $methods = json_decode((string) ($setting['value'] ?? ''), true);
        if (! is_array($methods) || $methods === []) {
            throw ValidationException::withMessages([
                'value' => ['يجب حفظ طريقة دفع واحدة على الأقل.'],
            ]);
        }

        $values = [];
        foreach ($methods as $method) {
            if (! is_array($method)
                || ! is_string($method['value'] ?? null)
                || trim($method['value']) === ''
                || ! is_string($method['label'] ?? null)
                || trim($method['label']) === ''
                || ! filter_var($method['account_id'] ?? null, FILTER_VALIDATE_INT)) {
                throw ValidationException::withMessages([
                    'value' => ['كل طريقة دفع يجب أن تحتوي على معرّف واسم وحساب.'],
                ]);
            }

            $key = mb_strtolower(trim($method['value']));
            if (in_array($key, $values, true)) {
                throw ValidationException::withMessages([
                    'value' => ['لا يمكن تكرار معرّف طريقة الدفع.'],
                ]);
            }
            $values[] = $key;

            $accountExists = Account::where('restaurant_id', $restaurantId)
                ->whereKey((int) $method['account_id'])
                ->where('is_active', true)
                ->exists();
            if (! $accountExists) {
                throw ValidationException::withMessages([
                    'value' => ['اربط كل طريقة دفع بحساب نشط من المطعم الحالي.'],
                ]);
            }
        }
    }
}

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = Notification::where('restaurant_id', $restaurantId);

        // If no specific user_id, show for current user OR global (user_id null)
        $query->where(function ($q) use ($request) {
            $q->whereNull('user_id')
                ->orWhere('user_id', $request->user()->id);
        });

        if ($request->has('unread_only')) {
            $query->where('is_read', false);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    public function markRead(Request $request, Notification $notification)
    {
        if ($notification->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        if ($notification->user_id && $notification->user_id !== $request->user()->id) {
            abort(403);
        }
        $notification->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'تم تنفيذ الإشعار كمقروء']);
    }
}
