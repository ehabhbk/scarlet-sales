<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_list_settings(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->getJson('/api/settings', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_can_update_restaurant_info(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->putJson('/api/settings/restaurant', [
            'name' => 'Scarlet Restaurant',
            'phone' => '+961 1 234567',
            'email' => 'info@scarletresta.com',
            'address' => 'Test Address, Beirut',
            'currency' => 'USD',
            'tax_rate' => 10.00,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['name' => 'Scarlet Restaurant', 'currency' => 'USD']);
    }

    public function test_can_update_language_settings(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->putJson('/api/settings/language', [
            'language' => 'ar',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['language' => 'ar']);
    }

    public function test_can_update_tax_rate(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->putJson('/api/settings/tax', [
            'tax_rate' => 15.00,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['tax_rate' => 15.00]);
    }
}