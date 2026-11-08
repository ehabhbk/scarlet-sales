<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_list_expenses(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->getJson('/api/expenses', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_can_create_expense(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->postJson('/api/expenses', [
            'title' => 'Test Expense',
            'amount' => 50.00,
            'category' => 'operations',
            'payment_method' => 'cash',
            'description' => 'Test expense description',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['expense', 'message']);
        $this->assertDatabaseHas('expenses', ['title' => 'Test Expense']);
    }

    public function test_can_update_expense(): void
    {
        $expense = Expense::factory()->create();
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->putJson('/api/expenses/' . $expense->id, [
            'amount' => 75.00,
            'category' => 'salaries',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['amount' => 75.00, 'category' => 'salaries']);
    }

    public function test_can_delete_expense(): void
    {
        $expense = Expense::factory()->create();
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->deleteJson('/api/expenses/' . $expense->id, [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(204);
    }
}