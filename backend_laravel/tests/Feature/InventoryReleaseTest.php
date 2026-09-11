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

final class InventoryReleaseTest extends TestCase
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
            'email'             => 'rel_stk_' . bin2hex(random_bytes(4)) . '@example.com',
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
            'slug' => 'cat-rel-' . bin2hex(random_bytes(4)),
            'name' => 'Rel Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-rel-' . bin2hex(random_bytes(4)),
            'name'        => 'Rel Product',
            'status'      => 'draft',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-REL-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 99.99,
            'is_active'  => true,
        ]);

        // Receive 10 units, reserve 5
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/receive", ['quantity' => 10]);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/reserve", ['quantity' => 5]);
    }

    public function test_valid_reserved_inventory_can_be_released(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/release", [
                'quantity' => 3,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.quantity_on_hand', 10)
            ->assertJsonPath('data.quantity_reserved', 2)
            ->assertJsonPath('data.quantity_available', 8);

        // Movement record check: positive delta (+3)
        $movement = InventoryMovement::where('variant_id', $this->variant->id)
            ->where('movement_type', 'release')
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals(3, $movement->quantity_delta);
        $this->assertEquals(10, $movement->on_hand_after);
    }

    public function test_releasing_more_than_reserved_quantity_fails(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/release", [
                'quantity' => 10,
            ]);

        $response->assertStatus(422);
    }
}
