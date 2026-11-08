<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->product = Product::factory()->create(['sale_price' => 15.00]);
    }

    public function test_can_create_order(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->postJson('/api/orders', [
            'table_id' => null,
            'type' => 'takeaway',
            'payment_method' => 'cash',
            'amount_paid' => 15.00,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['order', 'message']);
        $this->assertDatabaseHas('orders', ['order_number' => 'ORD-']);
    }

    public function test_can_get_order_with_relations(): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
        ]);
        $order->load(['items.product', 'table', 'customer', 'cashier', 'waiter', 'statusHistories.changedBy', 'payments']);

        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->getJson('/api/orders/' . $order->id, [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_cancel_order(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);

        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->postJson('/api/orders/' . $order->id . '/cancel', [
            'reason' => 'Test cancellation',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'تم إلغاء الطلب']);
    }

    public function test_record_payment(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);

        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->postJson('/api/orders/' . $order->id . '/payment', [
            'amount' => 15.00,
            'method' => 'cash',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['payment', 'order']);
    }
}