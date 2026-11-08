<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_list_employees(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->getJson('/api/employees', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_can_create_employee(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->postJson('/api/employees', [
            'name' => 'Test Employee',
            'job_title' => 'Server',
            'phone' => '+961 3 123456',
            'email' => 'test@example.com',
            'hire_date' => now()->toDateString(),
            'salary' => 1500.00,
            'status' => 'active',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['employee', 'message']);
        $this->assertDatabaseHas('employees', ['name' => 'Test Employee']);
    }

    public function test_can_update_employee(): void
    {
        $employee = Employee::factory()->create();
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->putJson('/api/employees/' . $employee->id, [
            'phone' => '+961 3 789012',
            'salary' => 1800.00,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['phone' => '+961 3 789012', 'salary' => 1800.00]);
    }

    public function test_can_delete_employee(): void
    {
        $employee = Employee::factory()->create();
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->deleteJson('/api/employees/' . $employee->id, [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(204);
    }
}