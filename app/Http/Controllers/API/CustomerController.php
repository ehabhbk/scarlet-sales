<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = Customer::where('restaurant_id', $restaurantId)->where('is_active', true);

        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('phone', 'like', "%{$request->search}%");
            });
        }

        return response()->json($query->orderByDesc('total_spent')->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'birth_date' => 'nullable|date',
            'customer_type' => 'sometimes|in:regular,vip,corporate',
            'notes' => 'nullable|string',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['customer_type'] = $validated['customer_type'] ?? 'regular';
        $customer = Customer::create($validated);
        return response()->json(['customer' => $customer, 'message' => 'تم إضافة العميل'], 201);
    }

    public function show(Customer $customer)
    {
        if ($customer->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['customer' => $customer->load('orders')]);
    }

    public function update(Request $request, Customer $customer)
    {
        if ($customer->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:50',
            'customer_type' => 'sometimes|in:regular,vip,corporate',
            'is_active' => 'sometimes|boolean',
        ]);
        $customer->update($validated);
        return response()->json(['customer' => $customer->fresh(), 'message' => 'تم تحديث العميل']);
    }

    public function destroy(Customer $customer)
    {
        if ($customer->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $customer->delete();
        return response()->json(['message' => 'تم حذف العميل']);
    }
}
