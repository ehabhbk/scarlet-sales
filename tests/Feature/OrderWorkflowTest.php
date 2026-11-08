<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderKitchenStation;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::create([
            'name' => 'Test Restaurant',
            'slug' => Str::uuid()->toString(),
            'tax_rate' => 0,
        ]);
        $this->user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'username' => Str::uuid()->toString(),
        ]);
        $permissionNames = [
            'view_orders',
            'view_all_orders',
            'create_orders',
            'edit_orders',
            'edit_order_payment',
            'delete_orders',
            'cancel_orders',
            'refund_orders',
            'update_kitchen_order',
            'view_all_kitchen_orders',
        ];
        $permissions = collect($permissionNames)->map(fn (string $name) => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'sanctum',
        ]));
        $role = Role::create(['name' => 'Order Workflow Test', 'guard_name' => 'sanctum']);
        $role->syncPermissions($permissions);
        $this->user->assignRole($role);
        $cashAccount = Account::create([
            'restaurant_id' => $this->restaurant->id,
            'code' => '1001',
            'name' => 'حساب النقد',
            'type' => 'asset',
            'is_active' => true,
        ]);
        Setting::create([
            'restaurant_id' => $this->restaurant->id,
            'group' => 'pos',
            'key' => 'payment_methods',
            'value' => json_encode([
                ['value' => 'cash', 'label' => 'نقدي', 'account_id' => $cashAccount->id],
            ]),
            'type' => 'json',
        ]);
        $this->actingAs($this->user, 'sanctum');
    }

    public function test_orders_can_be_listed_with_items_for_a_shift(): void
    {
        $shift = $this->createShift();
        $product = $this->createProduct('Tea', 5, '/storage/products/tea.jpg');
        $order = $this->createOrder($shift, $product, 5);
        $otherShift = $this->createShift();
        $otherProduct = $this->createProduct('Coffee', 7);
        $this->createOrder($otherShift, $otherProduct, 7);

        $this->getJson('/api/orders?shift_id='.$shift->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.shift_id', $shift->id)
            ->assertJsonPath('data.0.items.0.product.image', route('media.show', ['path' => 'products/tea.jpg']));
    }

    public function test_replacing_order_items_refunds_the_price_difference(): void
    {
        $shift = $this->createShift();
        $oldProduct = $this->createProduct('Old item', 10);
        $newProduct = $this->createProduct('Replacement', 8);
        $order = $this->createOrder($shift, $oldProduct, 10, 10);

        $this->putJson('/api/orders/'.$order->id, [
            'items' => [
                ['product_id' => $newProduct->id, 'quantity' => 1],
            ],
            'amount_received' => 0,
            'payment_method' => 'cash',
        ])->assertOk()
            ->assertJsonPath('order.total_amount', 8)
            ->assertJsonPath('order.amount_paid', 8)
            ->assertJsonPath('order.payment_status', 'paid')
            ->assertJsonPath('order.items.0.product.name', 'Replacement');

        $this->assertDatabaseHas('payments', [
            'paymentable_type' => Order::class,
            'paymentable_id' => $order->id,
            'amount' => -2,
            'notes' => 'استرداد فرق تعديل أصناف الطلب',
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $newProduct->id,
            'quantity' => 1,
        ]);
    }

    public function test_replacing_order_items_can_collect_the_additional_amount(): void
    {
        $shift = $this->createShift();
        $oldProduct = $this->createProduct('Old item', 10);
        $newProduct = $this->createProduct('Replacement', 15);
        $order = $this->createOrder($shift, $oldProduct, 10, 10);

        $this->putJson('/api/orders/'.$order->id, [
            'items' => [
                ['product_id' => $newProduct->id, 'quantity' => 1],
            ],
            'amount_received' => 5,
            'payment_method' => 'cash',
        ])->assertOk()
            ->assertJsonPath('order.total_amount', 15)
            ->assertJsonPath('order.amount_paid', 15)
            ->assertJsonPath('order.payment_status', 'paid');

        $this->assertDatabaseHas('payments', [
            'paymentable_type' => Order::class,
            'paymentable_id' => $order->id,
            'amount' => 5,
            'notes' => 'دفعة فرق تعديل أصناف الطلب',
        ]);
    }

    public function test_paid_orders_can_be_refunded_once(): void
    {
        $shift = $this->createShift();
        $product = $this->createProduct('Tea', 10);
        $order = $this->createOrder($shift, $product, 10, 10);

        $this->postJson('/api/orders/'.$order->id.'/refund', ['reason' => 'Customer request'])
            ->assertOk()
            ->assertJsonPath('order.payment_status', 'refunded')
            ->assertJsonPath('order.amount_paid', '0.0000');

        $this->assertDatabaseHas('payments', [
            'paymentable_type' => Order::class,
            'paymentable_id' => $order->id,
            'amount' => -10,
        ]);
        $this->postJson('/api/orders/'.$order->id.'/refund')
            ->assertStatus(400);
    }

    public function test_cancelled_paid_orders_can_still_be_refunded(): void
    {
        $shift = $this->createShift();
        $product = $this->createProduct('Tea', 10);
        $order = $this->createOrder($shift, $product, 10, 10);

        $this->postJson('/api/orders/'.$order->id.'/cancel')->assertOk();
        $this->postJson('/api/orders/'.$order->id.'/refund')
            ->assertOk()
            ->assertJsonPath('order.payment_status', 'refunded');
    }

    public function test_unpaid_orders_can_be_deleted(): void
    {
        $shift = $this->createShift();
        $product = $this->createProduct('Tea', 10);
        $order = $this->createOrder($shift, $product, 10);

        $this->deleteJson('/api/orders/'.$order->id)
            ->assertOk();

        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_new_order_numbers_skip_soft_deleted_orders(): void
    {
        $shift = $this->createShift();
        $product = $this->createProduct('Tea', 10);
        $deletedOrder = $this->createOrder($shift, $product, 10);
        $date = now()->format('Ymd');
        $deletedOrder->update(['order_number' => "ORD-{$date}-0007"]);
        $deletedOrder->delete();

        $this->postJson('/api/orders', [
            'type' => 'takeaway',
            'payment_method' => 'cash',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertCreated()
            ->assertJsonPath('order.order_number', "ORD-{$date}-0008");

        $this->postJson('/api/orders', [
            'type' => 'takeaway',
            'payment_method' => 'cash',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertCreated()
            ->assertJsonPath('order.order_number', "ORD-{$date}-0009");

        $this->assertDatabaseHas('order_number_sequences', [
            'sequence_date' => now()->toDateString(),
            'last_sequence' => 9,
        ]);
    }

    public function test_orders_cannot_be_created_without_an_open_shift(): void
    {
        $product = $this->createProduct('Tea', 10);

        $this->postJson('/api/orders', [
            'type' => 'takeaway',
            'payment_method' => 'cash',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن إنشاء طلب قبل فتح وردية جديدة.')
            ->assertJsonValidationErrors('shift');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_kitchen_item_can_be_marked_ready_using_status_payload(): void
    {
        $shift = $this->createShift();
        $product = $this->createProduct('Tea', 10);
        $order = $this->createOrder($shift, $product, 10);
        $item = $order->items()->firstOrFail();
        $station = KitchenStation::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Drinks',
        ]);
        $assignment = OrderKitchenStation::create([
            'order_item_id' => $item->id,
            'station_id' => $station->id,
        ]);

        $this->postJson('/api/kitchen/order-items/'.$item->id.'/status', [
            'status' => 'ready',
            'station_id' => $station->id,
        ])->assertOk()
            ->assertJsonPath('order_item.kitchen_status', 'ready');

        $this->assertNotNull($assignment->fresh()->completed_at);
    }

    private function createShift(): Shift
    {
        return Shift::create([
            'restaurant_id' => $this->restaurant->id,
            'user_id' => $this->user->id,
            'name' => 'Test shift',
            'shift_date' => now()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'status' => 'open',
            'opening_cash' => 0,
        ]);
    }

    private function createProduct(string $name, float $price, ?string $image = null): Product
    {
        return Product::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::uuid(),
            'sale_price' => $price,
            'cost_price' => $price / 2,
            'image' => $image,
        ]);
    }

    private function createOrder(Shift $shift, Product $product, float $total, float $paid = 0): Order
    {
        $order = Order::create([
            'restaurant_id' => $this->restaurant->id,
            'shift_id' => $shift->id,
            'cashier_id' => $this->user->id,
            'order_number' => 'ORD-'.Str::uuid(),
            'type' => 'takeaway',
            'status' => 'pending',
            'payment_status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
            'payment_method' => 'cash',
            'subtotal' => $total,
            'total_amount' => $total,
            'amount_paid' => $paid,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $total,
            'total_price' => $total,
            'kitchen_status' => 'pending',
        ]);

        return $order;
    }
}
