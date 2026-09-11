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

final class InventoryDeductionTest extends TestCase
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
            'email'             => 'ded_stk_' . bin2hex(random_bytes(4)) . '@example.com',
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
            'slug' => 'cat-ded-' . bin2hex(random_bytes(4)),
            'name' => 'Ded Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-ded-' . bin2hex(random_bytes(4)),
            'name'        => 'Ded Product',
            'status'      => 'draft',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-DED-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 99.99,
            'is_active'  => true,
        ]);

        // Receive 20 units, reserve 5 for pending order
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/receive", ['quantity' => 20]);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/reserve", ['quantity' => 5]);
    }

    public function test_physical_stock_deduction_on_delivery(): void
    {
        // Deduct 5 units (order delivered)
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/deduct", [
                'quantity' => 5,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.quantity_on_hand', 15)
            ->assertJsonPath('data.quantity_reserved', 0)
            ->assertJsonPath('data.quantity_available', 15);

        // Verify sale movement created in ledger
        $movement = InventoryMovement::where('variant_id', $this->variant->id)
            ->where('movement_type', 'sale')
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals(-5, $movement->quantity_delta);
        $this->assertEquals(15, $movement->on_hand_after);
    }
}
