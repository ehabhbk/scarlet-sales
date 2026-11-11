<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => 'nullable|exists:categories,id',
            'kitchen_station_id' => [
                'nullable',
                'integer',
                Rule::exists('kitchen_stations', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $this->user()->restaurant_id)
                    ->where('is_active', true)),
            ],
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:products,slug',
            'sku' => 'nullable|string|max:100|unique:products,sku',
            'barcode' => 'nullable|string|max:100|unique:products,barcode',
            'description' => 'nullable|string',
            'image' => 'nullable|string|max:2048',
            'image_file' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'sale_price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:1',
            'track_inventory' => 'boolean',
            'current_stock' => 'nullable|integer|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'sale_unit' => 'nullable|string|max:50',
            'purchase_unit' => 'nullable|string|max:50',
            'main_unit' => 'nullable|string|max:50',
            'sub_unit' => 'nullable|string|max:50',
            'conversion_factor' => 'nullable|numeric|min:0.001',
            'sub_unit_price' => 'nullable|numeric|min:0',
            'expiry_alert_days' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'is_available' => 'boolean',
            'is_featured' => 'boolean',
            'is_best_seller' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $this->user()->restaurant_id)),
            ],
            'initial_quantity' => 'nullable|numeric|min:0',
            'expiry_date' => 'nullable|date',
            'batch_number' => 'nullable|string|max:100',
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $this->user()->restaurant_id)),
            ],
        ];
    }
}
