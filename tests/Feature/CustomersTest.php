<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_list_customers(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->getJson('/api/customers', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_can_create_customer(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->postJson('/api/customers', [
            'name' => 'Test Customer',
            'phone' => '+961 3 123456',
            'email' => 'test@example.com',
            'address' => 'Test Address',
            'type' => 'regular',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['customer', 'message']);
        $this->assertDatabaseHas('customers', ['name' => 'Test Customer']);
    }

    public function test_can_update_customer(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->putJson('/api/customers/' . $customer->id, [
            'phone' => '+961 3 789012',
            'type' => 'vip',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['phone' => '+961 3 789012', 'type' => 'vip']);
    }

    public function test_can_delete_customer(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->deleteJson('/api/customers/' . $customer->id, [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(204);
    }
}