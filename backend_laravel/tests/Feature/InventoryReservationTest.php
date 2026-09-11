<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Modules\Inventory\Infrastructure\Persistence\Models\InventoryMovement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class InventoryReservationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected string $adminToken;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->adminUser = User::create([
            'email'             => 'res_stk_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => $adminRole->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);

        $rawToken = 'token-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id'    => $this->adminUser->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
        $this->adminToken = $rawToken;

        $category = Category::create([
            'slug' => 'cat-res-' . bin2hex(random_bytes(4)),
            'name' => 'Res Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-res-' . bin2hex(random_bytes(4)),
            'name'        => 'Res Product',
            'status'      => 'draft',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-RES-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 99.99,
            'is_active'  => true,
        ]);

        // Receive 10 units
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/receive", [
                'quantity' => 10,
            ]);
    }

    public function test_sufficient_inventory_can_be_reserved(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/reserve", [
                'quantity' => 4,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.quantity_on_hand', 10)
            ->assertJsonPath('data.quantity_reserved', 4)
            ->assertJsonPath('data.quantity_available', 6);

        // Movement record check: negative delta (-4)
        $movement = InventoryMovement::where('variant_id', $this->variant->id)
            ->where('movement_type', 'reservation')
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals(-4, $movement->quantity_delta);
        $this->assertEquals(10, $movement->on_hand_after);
    }

    public function test_reservation_fails_when_stock_insufficient_and_backorder_disabled(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/reserve", [
                'quantity' => 15,
            ]);

        $response->assertStatus(422);
    }

    public function test_reservation_succeeds_when_backorder_enabled(): void
    {
        // Enable backorder
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->patchJson("/api/v1/admin/inventory/variants/{$this->variant->id}/settings", [
                'allow_backorder' => true,
            ]);

        // Reserve 15 units when on_hand is 10
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/reserve", [
                'quantity' => 15,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.quantity_on_hand', 10)
            ->assertJsonPath('data.quantity_reserved', 15)
            ->assertJsonPath('data.quantity_available', 0);
    }
}
