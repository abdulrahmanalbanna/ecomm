<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Modules\Inventory\Infrastructure\Persistence\Models\Inventory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CartInventoryAvailabilityTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::where('name', 'customer')->first() ?? Role::first();
        $this->user = User::create([
            'email'             => 'cart_inv_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => $role->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);

        $rawToken = 'token-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id'    => $this->user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
        $this->token = $rawToken;
    }

    public function test_reject_adding_quantity_exceeding_stock_when_backorder_disabled(): void
    {
        $category = Category::create(['slug' => 'cat-inv-a-' . bin2hex(random_bytes(4)), 'name' => 'Inv Category']);
        $product = Product::create(['category_id' => $category->id, 'slug' => 'prod-inv-a-' . bin2hex(random_bytes(4)), 'name' => 'Inv Prod', 'status' => 'published', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-INV-A', 'price' => 10.00, 'is_active' => true]);

        Inventory::create([
            'variant_id'        => $variant->id,
            'quantity_on_hand'  => 5,
            'quantity_reserved' => 2, // available = 3
            'allow_backorder'   => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $variant->id,
                'quantity'   => 4,
            ]);

        $response->assertStatus(422);

        // Verify inventory quantities were NOT mutated
        $inv = Inventory::where('variant_id', $variant->id)->first();
        $this->assertEquals(5, $inv->quantity_on_hand);
        $this->assertEquals(2, $inv->quantity_reserved);
        $this->assertDatabaseMissing('inventory_movements', ['variant_id' => $variant->id]);
    }

    public function test_allow_adding_quantity_exceeding_stock_when_backorder_enabled(): void
    {
        $category = Category::create(['slug' => 'cat-inv-b-' . bin2hex(random_bytes(4)), 'name' => 'Inv Category']);
        $product = Product::create(['category_id' => $category->id, 'slug' => 'prod-inv-b-' . bin2hex(random_bytes(4)), 'name' => 'Inv Prod', 'status' => 'published', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-INV-B', 'price' => 10.00, 'is_active' => true]);

        Inventory::create([
            'variant_id'        => $variant->id,
            'quantity_on_hand'  => 2,
            'quantity_reserved' => 0,
            'allow_backorder'   => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $variant->id,
                'quantity'   => 10,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.items.0.quantity', 10);
    }
}
