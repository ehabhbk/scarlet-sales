<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\Shift;
use Spatie\Permission\Models\Role;
use App\Models\ShiftCashierBalance;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use App\Services\CashierAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerUserShiftBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function createRestaurant(): Restaurant
    {
        return Restaurant::create([
            'name' => 'Test Restaurant',
            'slug' => Str::uuid()->toString(),
            'tax_rate' => 0,
        ]);
    }

    private function createUser(Restaurant $restaurant): User
    {
        return User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'username' => Str::uuid()->toString(),
        ]);
    }

    private function grantPermissions(User $user, array $permissions): void
    {
        $permissionModels = collect($permissions)->map(fn (string $permission) => Permission::firstOrCreate([
            'name' => $permission,
            'guard_name' => 'sanctum',
        ]));
        $role = Role::create(['name' => 'Test Role '.$user->id, 'guard_name' => 'sanctum']);
        $role->syncPermissions($permissionModels);
        $user->assignRole($role);
    }

    public function test_per_user_opening_cash_and_per_cashier_closing(): void
    {
        $restaurant = $this->createRestaurant();

        $opener = $this->createUser($restaurant);
        $cashier2 = $this->createUser($restaurant);
        $this->grantPermissions($opener, ['open_shift', 'close_shift', 'create_orders', 'view_pos', 'view_all_orders', 'view_all_shifts']);
        $this->grantPermissions($cashier2, ['create_orders', 'view_pos']);

        app(CashierAccountService::class)->ensureFor($opener);
        app(CashierAccountService::class)->ensureFor($cashier2);

        $cashAccount = Account::create([
            'restaurant_id' => $restaurant->id,
            'code' => '1020',
            'name' => 'نقد',
            'type' => 'asset',
        ]);
        Setting::create([
            'restaurant_id' => $restaurant->id,
            'group' => 'pos',
            'key' => 'payment_methods',
            'type' => 'json',
            'value' => json_encode([
                ['value' => 'cash', 'account_id' => $cashAccount->id],
            ]),
        ]);

        // open shift as opener with opening cash 10000
        $this->actingAs($opener, 'sanctum')
            ->postJson('/api/shifts/open', [
                'name' => 'وردية',
                'shift_date' => now()->toDateString(),
                'start_time' => '08:00',
                'end_time' => '23:59',
                'opening_cash' => 10000,
            ])
            ->assertCreated();

        $shift = Shift::where('restaurant_id', $restaurant->id)->where('status', 'open')->firstOrFail();

        // cashier2 sets opening cash 20000
        $this->actingAs($cashier2, 'sanctum')
            ->postJson('/api/shifts/'.$shift->id.'/opening-cash', ['opening_cash' => 20000])
            ->assertOk();

        // create a product
        $product = \App\Models\Product::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Tea',
            'price' => 10,
            'sale_price' => 10,
            'is_active' => true,
        ]);

        // cashier2 creates an order (cash 50)
        $this->actingAs($cashier2, 'sanctum')
            ->postJson('/api/orders', [
                'type' => 'takeaway',
                'payment_method' => 'cash',
                'amount_paid' => 50,
                'items' => [['product_id' => $product->id, 'quantity' => 5]],
            ])
            ->assertCreated();

        // opener creates an order (cash 30)
        $this->actingAs($opener, 'sanctum')
            ->postJson('/api/orders', [
                'type' => 'takeaway',
                'payment_method' => 'cash',
                'amount_paid' => 30,
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
            ])
            ->assertCreated();

        // check current balances
        $this->actingAs($opener, 'sanctum')
            ->getJson('/api/shifts/current')
            ->assertOk()
            ->assertJsonPath('balance.opening_cash', 10000)
            ->assertJsonPath('balance.cash_revenue', 30)
            ->assertJsonPath('balance.expected_cash', 10030)
            ->assertJsonPath('needs_opening_cash', false);

        $this->actingAs($cashier2, 'sanctum')
            ->getJson('/api/shifts/current')
            ->assertOk()
            ->assertJsonPath('balance.opening_cash', 20000)
            ->assertJsonPath('balance.cash_revenue', 50)
            ->assertJsonPath('balance.expected_cash', 20050)
            ->assertJsonPath('needs_opening_cash', false);

        // close shift as opener with per-cashier actuals
        $this->actingAs($opener, 'sanctum')
            ->putJson('/api/shifts/'.$shift->id, [
                'status' => 'closed',
                'cashiers' => [
                    ['user_id' => $opener->id, 'actual_cash' => 10030],
                    ['user_id' => $cashier2->id, 'actual_cash' => 20050],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('shift.status', 'closed')
            ->assertJsonPath('message', 'تم إغلاق الوردية وإنشاء قيود الوردية بنجاح');

        $this->assertDatabaseHas('shifts', ['id' => $shift->id, 'status' => 'closed']);
        $this->assertNotNull(\App\Models\JournalEntry::where('reference', 'SHIFT-'.$shift->id)->first());
        $this->assertNotNull(\App\Models\JournalEntry::where('reference', 'SHIFT-'.$shift->id.'-U'.$cashier2->id)->first());

        $this->assertDatabaseHas('shift_cashier_balances', [
            'shift_id' => $shift->id,
            'user_id' => $opener->id,
            'opening_cash' => 10000,
            'expected_cash' => 10030,
            'actual_cash' => 10030,
        ]);
        $this->assertDatabaseHas('shift_cashier_balances', [
            'shift_id' => $shift->id,
            'user_id' => $cashier2->id,
            'opening_cash' => 20000,
            'expected_cash' => 20050,
            'actual_cash' => 20050,
        ]);

        $this->actingAs($opener, 'sanctum')
            ->getJson('/api/shifts/current')
            ->assertOk()
            ->assertJsonPath('shift', null);
    }
}
