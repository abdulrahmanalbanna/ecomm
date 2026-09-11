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

final class InventoryReceiveStockTest extends TestCase
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
            'email'             => 'rec_stk_' . bin2hex(random_bytes(4)) . '@example.com',
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
            'slug' => 'cat-rec-' . bin2hex(random_bytes(4)),
            'name' => 'Rec Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-rec-' . bin2hex(random_bytes(4)),
            'name'        => 'Rec Product',
            'status'      => 'draft',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-REC-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 99.99,
            'is_active'  => true,
        ]);
    }

    public function test_receiving_stock_increases_on_hand_and_available_quantities(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/receive", [
                'quantity' => 50,
                'note'     => 'Initial batch shipment from supplier',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.quantity_on_hand', 50)
            ->assertJsonPath('data.quantity_reserved', 0)
            ->assertJsonPath('data.quantity_available', 50);

        // Verify purchase movement created in ledger
        $movement = InventoryMovement::where('variant_id', $this->variant->id)
            ->where('movement_type', 'purchase')
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals(50, $movement->quantity_delta);
        $this->assertEquals(50, $movement->on_hand_after);
        $this->assertEquals('Initial batch shipment from supplier', $movement->note);
        $this->assertEquals($this->adminUser->id, $movement->created_by);
    }

    public function test_receiving_invalid_quantity_fails(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/v1/admin/inventory/variants/{$this->variant->id}/receive", [
                'quantity' => 0,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
    }
}
