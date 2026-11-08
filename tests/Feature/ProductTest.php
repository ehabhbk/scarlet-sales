<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = Category::factory()->create();
        $this->user = User::factory()->create();
    }

    public function test_can_list_products(): void
    {
        Product::factory()->count(5)->create([
            'category_id' => $this->category->id,
        ]);

        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->getJson('/api/products', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_can_create_product(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->postJson('/api/products', [
            'name' => 'Test Product',
            'sale_price' => 10.00,
            'cost_price' => 5.00,
            'category_id' => $this->category->id,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['product', 'message']);
        $this->assertDatabaseHas('products', ['name' => 'Test Product']);
    }

    public function test_can_filter_products_by_category(): void
    {
        Product::factory()->count(3)->create([
            'category_id' => $this->category->id,
        ]);
        Product::factory()->count(2)->create([
            'category_id' => null,
        ]);

        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->getJson('/api/products?category_id=' . $this->category->id, [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_can_search_products(): void
    {
        Product::factory()->create(['name' => 'Arabic Coffee']);

        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->getJson('/api/products?search=Coffee', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }
}