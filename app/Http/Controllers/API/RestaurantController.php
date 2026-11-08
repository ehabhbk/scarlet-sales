<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RestaurantController extends Controller
{
    public function show(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        if (!$restaurant) {
            abort(404, 'المطعم غير موجود');
        }
        return response()->json(['restaurant' => $this->restaurantData($restaurant)]);
    }

    public function update(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        if (!$restaurant) {
            abort(404);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:50',
            'address' => 'sometimes|string',
            'currency' => 'sometimes|string|max:10',
            'symbol' => 'sometimes|string|max:10',
            'tax_rate' => 'sometimes|numeric|min:0|max:1',
            'primary_color' => 'sometimes|string|max:20',
            'logo' => 'sometimes|nullable|string',
        ]);
        $restaurant->update($validated);
        return response()->json(['restaurant' => $this->restaurantData($restaurant->fresh()), 'message' => 'تم تحديث بيانات المطعم']);
    }

    public function uploadLogo(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        if (!$restaurant) {
            abort(404);
        }

        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,webp,svg|max:4096',
        ]);

        $oldLogoPath = PublicMediaUrl::storagePath($restaurant->logo);
        if ($oldLogoPath) {
            Storage::disk('public')->delete($oldLogoPath);
        }

        $restaurant->update(['logo' => $request->file('logo')->store('logos', 'public')]);

        return response()->json([
            'restaurant' => $this->restaurantData($restaurant->fresh()),
            'message' => 'تم تحديث شعار المطعم',
        ]);
    }

    public function removeLogo(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        if (!$restaurant) {
            abort(404);
        }

        $logoPath = PublicMediaUrl::storagePath($restaurant->logo);
        if ($logoPath) {
            Storage::disk('public')->delete($logoPath);
        }
        $restaurant->update(['logo' => null]);

        return response()->json([
            'restaurant' => $this->restaurantData($restaurant->fresh()),
            'message' => 'تم حذف شعار المطعم',
        ]);
    }

    private function restaurantData(Restaurant $restaurant): array
    {
        $data = $restaurant->toArray();
        $data['logo'] = PublicMediaUrl::make($restaurant->logo);

        return $data;
    }
}
