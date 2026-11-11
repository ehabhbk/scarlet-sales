<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseReceivingTest extends TestCase
{
    use RefreshDatabase;

    public function test_receiving_a_purchase_posts_stock_once_and_batch_edit_updates_product(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Receiving Restaurant',
            'slug' => Str::uuid()->toString(),
            'tax_rate' => 0,
        ]);

        $user = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'username' => Str::uuid()->toString(),
        ]);
        $this->grantPermissions($user, [
            'create_purchases',
            'edit_purchases',
            'view_warehouses',
            'edit_warehouses',
            'manage_stock',
        ]);

        $supplier = Supplier::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Supplier',
            'is_active' => true,
        ]);

        $account = Account::create([
            'restaurant_id' => $restaurant->id,
            'code' => '1001',
            'name' => 'Cash',
            'type' => 'asset',
            'is_active' => true,
        ]);

        Setting::create([
            'restaurant_id' => $restaurant->id,
            'group' => 'pos',
            'key' => 'payment_methods',
            'type' => 'json',
            'value' => json_encode([['value' => 'cash', 'account_id' => $account->id]]),
        ]);

        $warehouse = Warehouse::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Main Store',
            'type' => 'store',
            'is_active' => true,
        ]);

        $product = Product::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Sugar',
            'slug' => 'sugar-'.Str::uuid()->toString(),
            'sale_price' => 10,
            'cost_price' => 5,
            'track_inventory' => true,
            'is_available' => true,
        ]);

        $createResponse = $this->actingAs($user, 'sanctum')->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'account_id' => $account->id,
            'purchase_number' => 'PO-'.Str::uuid()->toString(),
            'status' => 'ordered',
            'payment_method' => 'cash',
            'amount_paid' => 0,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'main_quantity' => 4,
                'sub_quantity_per_main' => 12,
                'unit_name' => 'كرتون',
                'sub_unit_name' => 'قطعة',
                'unit_cost' => 5,
                'expiry_date' => '2027-01-01',
            ]],
        ])->assertCreated();

        $purchaseId = $createResponse->json('purchase.id');

        $this->assertSame(0, ProductBatch::where('product_id', $product->id)->count());

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/purchases/{$purchaseId}", ['status' => 'received'])
            ->assertOk();

        $batch = ProductBatch::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->firstOrFail();

        $this->assertSame(4.0, (float) $batch->quantity);
        $this->assertSame(5.0, (float) $batch->cost_price);

        // Saving the same received status again must not double-post stock.
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/purchases/{$purchaseId}", ['status' => 'received'])
            ->assertOk();

        $this->assertSame(
            1,
            ProductBatch::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->count()
        );
        $this->assertSame(
            4.0,
            (float) ProductBatch::whereKey($batch->id)->value('quantity')
        );

        // Editing the batch also updates the parent product's selling data.
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/warehouses/{$warehouse->id}/batches/{$batch->id}", [
                'quantity' => 5,
                'sale_price' => 12,
                'conversion_factor' => 12,
                'main_unit' => 'كرتون',
                'sub_unit' => 'قطعة',
                'expiry_date' => '2027-02-01',
            ])
            ->assertOk();

        $product->refresh();
        $this->assertSame(12.0, (float) $product->sale_price);
        $this->assertSame(12.0, (float) $product->conversion_factor);
        $this->assertSame('كرتون', $product->main_unit);
        $this->assertSame('قطعة', $product->sub_unit);

        $batch->refresh();
        $this->assertSame(5.0, (float) $batch->quantity);
        $this->assertSame('2027-02-01', $batch->expiry_date?->toDateString());
    }

    private function grantPermissions(User $user, array $permissionNames): void
    {
        $permissions = collect($permissionNames)->map(fn (string $name) => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'sanctum',
        ]));
        $role = Role::create(['name' => 'Purchase Test '.$user->id, 'guard_name' => 'sanctum']);
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }
}
