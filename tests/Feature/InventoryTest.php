<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->inventoryItem = InventoryItem::factory()->create();
    }

    public function test_can_list_inventory_items(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->getJson('/api/inventory-items', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_can_create_inventory_item(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->postJson('/api/inventory-items', [
            'name' => 'Test Ingredient',
            'sku' => 'TEST-001',
            'current_stock' => 100,
            'min_stock' => 10,
            'unit' => 'kg',
            'cost_per_unit' => 5.00,
            'supplier' => 'Test Supplier',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['inventory_item', 'message']);
        $this->assertDatabaseHas('inventory_items', ['sku' => 'TEST-001']);
    }

    public function test_can_filter_inventory_items_by_low_stock(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->getJson('/api/inventory-items?low_stock=true', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_can_update_inventory_item(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->putJson('/api/inventory-items/' . $this->inventoryItem->id, [
            'current_stock' => 150,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['current_stock' => 150]);
    }

    public function test_can_delete_inventory_item(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->deleteJson('/api/inventory-items/' . $this->inventoryItem->id, [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(204);
    }
}