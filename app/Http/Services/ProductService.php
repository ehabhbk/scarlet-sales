<?php

namespace App\Http\Services;

use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductModifier;
use App\Models\ProductSize;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $product = Product::create([
                'restaurant_id' => $data['restaurant_id'],
                'category_id' => $data['category_id'] ?? null,
                'kitchen_station_id' => $data['kitchen_station_id'] ?? null,
                'name' => $data['name'],
                'slug' => $data['slug'],
                'sku' => $data['sku'] ?? null,
                'barcode' => $data['barcode'] ?? null,
                'description' => $data['description'] ?? null,
                'image' => $data['image'] ?? null,
                'sale_price' => $data['sale_price'],
                'cost_price' => $data['cost_price'],
                'tax_rate' => $data['tax_rate'] ?? null,
                'track_inventory' => $data['track_inventory'] ?? true,
                'current_stock' => $data['current_stock'] ?? 0,
                'min_stock' => $data['min_stock'] ?? 0,
                'sale_unit' => $data['sale_unit'] ?? null,
                'purchase_unit' => $data['purchase_unit'] ?? null,
                'sub_unit' => $data['sub_unit'] ?? null,
                'conversion_factor' => $data['conversion_factor'] ?? 1,
                'expiry_alert_days' => $data['expiry_alert_days'] ?? 30,
                'low_stock_threshold' => $data['low_stock_threshold'] ?? 0,
                'is_available' => $data['is_available'] ?? true,
                'is_featured' => $data['is_featured'] ?? false,
                'is_best_seller' => $data['is_best_seller'] ?? false,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            if (! empty($data['sizes'])) {
                foreach ($data['sizes'] as $index => $size) {
                    ProductSize::create([
                        'product_id' => $product->id,
                        'name' => $size['name'],
                        'price_modifier' => $size['price_modifier'] ?? 0,
                        'sort_order' => $size['sort_order'] ?? $index,
                        'is_default' => $size['is_default'] ?? false,
                    ]);
                }
            }

            if (! empty($data['modifiers'])) {
                foreach ($data['modifiers'] as $modifier) {
                    $productModifier = ProductModifier::create([
                        'restaurant_id' => $data['restaurant_id'],
                        'product_id' => $product->id,
                        'name' => $modifier['name'],
                        'is_required' => $modifier['is_required'] ?? false,
                        'min_selection' => $modifier['min_selection'] ?? 0,
                        'max_selection' => $modifier['max_selection'] ?? 1,
                        'sort_order' => $modifier['sort_order'] ?? 0,
                        'is_active' => true,
                    ]);

                    if (! empty($modifier['options'])) {
                        foreach ($modifier['options'] as $optIndex => $opt) {
                            ModifierOption::create([
                                'modifier_id' => $productModifier->id,
                                'name' => $opt['name'],
                                'price_modifier' => $opt['price_modifier'] ?? 0,
                                'sort_order' => $opt['sort_order'] ?? $optIndex,
                                'is_default' => $opt['is_default'] ?? false,
                            ]);
                        }
                    }
                }
            }

            return $product->fresh(['category', 'sizes', 'modifiers.options']);
        });
    }

    public function update(Product $product, array $data): Product
    {
        $product->update(array_intersect_key($data, array_flip([
            'category_id', 'kitchen_station_id', 'name', 'slug', 'sku', 'barcode', 'description',
            'image', 'sale_price', 'cost_price', 'tax_rate', 'track_inventory',
            'current_stock', 'min_stock', 'sale_unit', 'purchase_unit', 'sub_unit',
            'conversion_factor', 'expiry_alert_days', 'low_stock_threshold',
            'is_available', 'is_featured',
            'is_best_seller', 'sort_order',
        ])));

        return $product->fresh();
    }
}
