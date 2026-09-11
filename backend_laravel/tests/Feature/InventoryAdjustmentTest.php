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

final class InventoryAdjustmentTest extends TestCase
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
            'email'             => 'adj_stk_' . bin2hex(random_bytes(4)) . '@example.com',
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
            'slug' => 'cat-adj-' . bin2hex(random_bytes(4)),
            'name' => 'Adj Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-adj-' . bin2hex(random_bytes(4)),
            'name'        => 'Adj Product',
            'status'      => 'draft',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-ADJ-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 99.99,
            'is_active'  => true,
        ]);

        // Seed 20 stock units
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/receive", [
                'quantity' => 20,
            ]);
    }

    public function test_positive_and_valid_negative_adjustments(): void
    {
        // Positive adjustment (+5)
        $responsePos = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/adjust", [
                'quantity_delta' => 5,
                'note'           => 'Audit count correction',
            ]);

        $responsePos->assertStatus(200)
            ->assertJsonPath('data.quantity_on_hand', 25)
            ->assertJsonPath('data.quantity_available', 25);

        // Negative adjustment (-10)
        $responseNeg = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/adjust", [
                'quantity_delta' => -10,
                'note'           => 'Damaged items removed',
            ]);

        $responseNeg->assertStatus(200)
            ->assertJsonPath('data.quantity_on_hand', 15)
            ->assertJsonPath('data.quantity_available', 15);

        // Verify adjustment movements created
        $movements = InventoryMovement::where('variant_id', $this->variant->id)
            ->where('movement_type', 'adjustment')
            ->orderBy('id', 'asc')
            ->get();

        $this->assertCount(2, $movements);
        $this->assertEquals(5, $movements[0]->quantity_delta);
        $this->assertEquals(25, $movements[0]->on_hand_after);
        $this->assertEquals(-10, $movements[1]->quantity_delta);
        $this->assertEquals(15, $movements[1]->on_hand_after);
    }

    public function test_adjustment_violating_negative_stock_bounds_fails(): void
    {
        // Attempting to adjust by -50 when on_hand is 20
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/adjust", [
                'quantity_delta' => -50,
                'note'           => 'Invalid reduction',
            ]);

        $response->assertStatus(422);

        // On hand should remain unchanged at 20
        $this->assertDatabaseHas('inventory', [
            'variant_id'       => $this->variant->id,
            'quantity_on_hand' => 20,
        ]);
    }
}
