<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\Restaurant;
use App\Models\Category;
use App\Models\Product;
use App\Models\Area;
use App\Models\Table;
use App\Models\KitchenStation;
use App\Models\InventoryUnit;
use App\Models\Supplier;
use App\Models\InventoryItem;
use App\Models\Customer;
use App\Models\User;
use App\Services\CashierAccountService;
use App\Models\Employee;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
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

        // Categories (idempotent by name+restaurant)
        $categoryNames = [
            ['name' => 'المشروبات الساخنة', 'color' => '#ef4444'],
            ['name' => 'المشروبات الباردة', 'color' => '#3b82f6'],
            ['name' => 'الوجبات الرئيسية', 'color' => '#10b981'],
            ['name' => 'المقبلات', 'color' => '#f59e0b'],
            ['name' => 'الحلويات', 'color' => '#ec4899'],
        ];
        $categories = [];
        foreach ($categoryNames as $idx => $cn) {
            $categories[] = Category::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'name' => $cn['name']],
                ['slug' => Str::slug($cn['name']), 'color' => $cn['color'], 'sort_order' => $idx, 'is_active' => true, 'is_featured' => false]
            );
        }

        // Products (idempotent by sku)
        $products = [
            ['name' => 'قهوة عربية', 'category' => 'المشروبات الساخنة', 'sale_price' => 12.00, 'cost_price' => 4.00, 'stock' => 100, 'best' => false],
            ['name' => 'لاتيه', 'category' => 'المشروبات الساخنة', 'sale_price' => 18.00, 'cost_price' => 7.00, 'stock' => 80, 'best' => false],
            ['name' => 'كابوشينو', 'category' => 'المشروبات الساخنة', 'sale_price' => 18.00, 'cost_price' => 7.00, 'stock' => 80, 'best' => true],
            ['name' => 'أيس كوفي', 'category' => 'المشروبات الباردة', 'sale_price' => 20.00, 'cost_price' => 8.00, 'stock' => 60, 'best' => false],
            ['name' => 'عصير برتقال', 'category' => 'المشروبات الباردة', 'sale_price' => 15.00, 'cost_price' => 6.00, 'stock' => 50, 'best' => false],
            ['name' => 'برجر دجاج', 'category' => 'الوجبات الرئيسية', 'sale_price' => 35.00, 'cost_price' => 14.00, 'stock' => 40, 'best' => false],
            ['name' => 'برجر لحم', 'category' => 'الوجبات الرئيسية', 'sale_price' => 45.00, 'cost_price' => 20.00, 'stock' => 40, 'best' => true],
            ['name' => 'بطاطس مقلية', 'category' => 'المقبلات', 'sale_price' => 15.00, 'cost_price' => 5.00, 'stock' => 60, 'best' => false],
            ['name' => 'ناجتس دجاج', 'category' => 'المقبلات', 'sale_price' => 22.00, 'cost_price' => 9.00, 'stock' => 50, 'best' => false],
            ['name' => 'كعكة شوكولاتة', 'category' => 'الحلويات', 'sale_price' => 25.00, 'cost_price' => 10.00, 'stock' => 30, 'best' => false],
        ];
        foreach ($products as $idx => $p) {
            $cat = Category::where('restaurant_id', $restaurant->id)->where('name', $p['category'])->first();
            Product::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'sku' => 'SKU-' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT)],
                [
                    'category_id' => $cat?->id,
                    'name' => $p['name'],
                    'slug' => Str::slug($p['name']),
                    'barcode' => 'BC-' . str_pad($idx + 1, 6, '0', STR_PAD_LEFT),
                    'sale_price' => $p['sale_price'],
                    'cost_price' => $p['cost_price'],
                    'current_stock' => $p['stock'],
                    'min_stock' => 5,
                    'is_available' => true,
                    'is_featured' => false,
                    'is_best_seller' => $p['best'],
                    'sort_order' => $idx,
                ]
            );
        }

        // Areas & Tables
        $mainHall = Area::updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'الصالة الرئيسية'],
            ['sort_order' => 1, 'is_active' => true]
        );
        $vip = Area::updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'الصالون الخاص (VIP)'],
            ['sort_order' => 2, 'is_active' => true]
        );
        for ($i = 1; $i <= 10; $i++) {
            Table::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'code' => 'T-' . str_pad($i, 3, '0', STR_PAD_LEFT)],
                [
                    'area_id' => $i <= 6 ? $mainHall->id : $vip->id,
                    'name' => 'طاولة ' . $i,
                    'capacity' => $i <= 6 ? 4 : 6,
                    'status' => 'available',
                    'is_active' => true,
                ]
            );
        }

        // Kitchen Stations
        $stations = ['الشواء', 'المقليات', 'المشروبات', 'الحلويات', 'المقبلات'];
        $colors = ['#ef4444', '#f59e0b', '#3b82f6', '#ec4899', '#10b981'];
        foreach ($stations as $idx => $name) {
            KitchenStation::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'name' => $name],
                ['color' => $colors[$idx] ?? '#6b7280', 'sort_order' => $idx, 'is_active' => true]
            );
        }

        // Inventory Units
        $unitsData = [['كيلوغرام', 'kg'], ['قطعة', 'pcs'], ['لتر', 'L'], ['صندوق', 'box'], ['حزمة', 'bundle']];
        $units = [];
        foreach ($unitsData as $idx => $ud) {
            $units[] = InventoryUnit::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'abbreviation' => $ud[1]],
                ['name' => $ud[0], 'sort_order' => $idx, 'is_active' => true]
            );
        }
        $unitKg = InventoryUnit::where('restaurant_id', $restaurant->id)->where('abbreviation', 'kg')->first();
        $unitL = InventoryUnit::where('restaurant_id', $restaurant->id)->where('abbreviation', 'L')->first();

        // Suppliers
        $supplier1 = Supplier::updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'مؤسسة الأغذية المتحدة'],
            ['phone' => '+966500000010', 'contact_person' => 'أحمد محمد', 'balance' => 0, 'is_active' => true]
        );

        // Inventory Items
        $invItems = [
            ['name' => 'دجاج طازج', 'unit' => 'kg', 'stock' => 50, 'min' => 10, 'cost' => 18.00],
            ['name' => 'لحم بقري', 'unit' => 'kg', 'stock' => 30, 'min' => 5, 'cost' => 45.00],
            ['name' => 'بطاطس', 'unit' => 'kg', 'stock' => 40, 'min' => 8, 'cost' => 3.00],
            ['name' => 'بن قهوة', 'unit' => 'kg', 'stock' => 20, 'min' => 3, 'cost' => 35.00],
            ['name' => 'حليب', 'unit' => 'L', 'stock' => 30, 'min' => 5, 'cost' => 5.00],
        ];
        foreach ($invItems as $idx => $ii) {
            $unit = $ii['unit'] === 'kg' ? $unitKg : $unitL;
            InventoryItem::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'sku' => 'INV-' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT)],
                [
                    'unit_id' => $unit->id,
                    'supplier_id' => $supplier1->id,
                    'name' => $ii['name'],
                    'category' => 'general',
                    'current_stock' => $ii['stock'],
                    'min_stock' => $ii['min'],
                    'reorder_qty' => $ii['stock'] * 2,
                    'cost_per_unit' => $ii['cost'],
                    'is_active' => true,
                ]
            );
        }

        // Customers
        for ($i = 1; $i <= 5; $i++) {
            Customer::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'phone' => '+966500000' . str_pad($i, 3, '0', STR_PAD_LEFT)],
                [
                    'name' => 'عميل تجريبي ' . $i,
                    'email' => 'customer' . $i . '@example.com',
                    'customer_type' => 'regular',
                    'loyalty_points' => 0,
                    'total_spent' => 0,
                    'total_orders' => 0,
                    'is_active' => true,
                ]
            );
        }

        // Employees & Users
        $roles = ['Cashier', 'Waiter', 'Chef', 'Accountant', 'Inventory Manager'];
        foreach ($roles as $idx => $jobTitle) {
            $user = User::updateOrCreate(
                ['email' => strtolower($jobTitle) . '@scarletresta.com'],
                [
                    'name' => 'موظف ' . $jobTitle,
                    'password' => 'password123',
                    'restaurant_id' => $restaurant->id,
                    'phone' => '+9665000001' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                    'is_active' => true,
                ]
            );
            $role = Role::where('name', $jobTitle)->where('guard_name', 'sanctum')->first();
            if ($role && !$user->hasRole($jobTitle, 'sanctum')) {
                $user->assignRole($role);
            }
            $accountService = app(CashierAccountService::class);
            $accountService->ensurePayAccountFor($user);
            if ($accountService->isCashierRole($jobTitle)) {
                $accountService->ensureFor($user);
            }

            Employee::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'employee_id' => 'EMP-' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT)],
                [
                    'user_id' => $user->id,
                    'name' => 'موظف ' . $jobTitle,
                    'job_title' => $jobTitle,
                    'phone' => '+9665000001' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                    'hire_date' => now()->subYear(),
                    'salary' => 5000.00,
                    'pay_account_id' => $user->pay_account_id,
                    'status' => 'active',
                ]
            );
        }

        $this->command->info('Demo data seeded successfully!');
    }
}
