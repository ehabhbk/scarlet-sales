<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductMediaTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->restaurant = Restaurant::create([
            'name' => 'Test Restaurant',
            'slug' => Str::uuid()->toString(),
        ]);
        $this->user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'username' => Str::uuid()->toString(),
        ]);
        $this->actingAs($this->user);
    }

    public function test_product_images_can_be_uploaded_when_creating_and_updating_products(): void
    {
        $created = $this->post('/api/products', [
            'name' => 'Tea',
            'sale_price' => 5,
            'cost_price' => 2,
            'image_file' => UploadedFile::fake()->image('tea.png'),
        ])->assertCreated();

        $product = Product::firstOrFail();
        $oldPath = $product->image;
        Storage::disk('public')->assertExists($oldPath);
        $oldUrl = $created->json('data.image');
        $this->assertStringContainsString('/api/media/products/', $oldUrl);
        $this->get(parse_url($oldUrl, PHP_URL_PATH))->assertOk();

        $updated = $this->post('/api/products/' . $product->id, [
            '_method' => 'PUT',
            'image_file' => UploadedFile::fake()->image('tea-updated.png'),
        ])->assertOk();

        $product->refresh();
        $newPath = $product->image;
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing($oldPath);
        $newUrl = $updated->json('product.image');
        $this->assertStringContainsString('/api/media/products/', $newUrl);
        $this->get(parse_url($newUrl, PHP_URL_PATH))->assertOk();
    }

    public function test_authenticated_user_can_upload_a_profile_image(): void
    {
        $response = $this->post('/api/auth/avatar', [
            'image' => UploadedFile::fake()->image('avatar.png'),
        ])->assertOk()
            ->assertJsonPath('user.id', $this->user->id);

        $this->user->refresh();
        Storage::disk('public')->assertExists($this->user->avatar);
        $avatarUrl = $response->json('user.avatar');
        $this->assertStringContainsString('/api/media/users/', $avatarUrl);
        $this->get(parse_url($avatarUrl, PHP_URL_PATH))->assertOk();
    }

    public function test_restaurant_logos_return_a_loadable_public_url(): void
    {
        $response = $this->post('/api/auth/restaurant/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk();

        $logoPath = $this->restaurant->fresh()->logo;
        Storage::disk('public')->assertExists($logoPath);
        $logoUrl = $response->json('restaurant.logo');
        $this->assertStringContainsString('/api/media/logos/', $logoUrl);
        $this->get(parse_url($logoUrl, PHP_URL_PATH))->assertOk();
    }

    public function test_legacy_storage_urls_are_converted_to_loadable_media_urls(): void
    {
        Storage::disk('public')->put('products/legacy.png', 'image content');
        $product = Product::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Legacy product',
            'slug' => Str::uuid()->toString(),
            'sale_price' => 3,
            'cost_price' => 1,
            'image' => 'http://localhost:8000/storage/products/legacy.png',
        ]);

        $response = $this->getJson('/api/products/' . $product->id)->assertOk();
        $imageUrl = $response->json('data.image');
        $this->assertStringContainsString('/api/media/products/legacy.png', $imageUrl);
        $this->get(parse_url($imageUrl, PHP_URL_PATH))->assertOk();
    }
}
