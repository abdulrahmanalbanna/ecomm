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

final class CartUpdateItemTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected string $token;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::where('name', 'customer')->first() ?? Role::first();
        $this->user = User::create([
            'email'             => 'cart_upd_' . bin2hex(random_bytes(4)) . '@example.com',
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

        $category = Category::create([
            'slug' => 'cat-upd-' . bin2hex(random_bytes(4)),
            'name' => 'Update Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-upd-' . bin2hex(random_bytes(4)),
            'name'        => 'Update Product',
            'status'      => 'published',
            'is_active'   => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-UPD-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 20.00,
            'is_active'  => true,
        ]);

        Inventory::create([
            'variant_id'        => $this->variant->id,
            'quantity_on_hand'  => 50,
            'quantity_reserved' => 0,
            'allow_backorder'   => false,
        ]);
    }

    public function test_update_item_quantity_is_absolute(): void
    {
        $addRes = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $this->variant->id,
                'quantity'   => 2,
            ]);
        $itemId = $addRes->json('data.items.0.id');

        $updRes = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/v1/cart/items/{$itemId}", [
                'quantity' => 5,
            ]);

        $updRes->assertStatus(200)
            ->assertJsonPath('data.total_quantity', 5)
            ->assertJsonPath('data.subtotal', '100.00')
            ->assertJsonPath('data.items.0.quantity', 5);
    }

    public function test_update_item_with_invalid_quantity_fails(): void
    {
        $addRes = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $this->variant->id,
                'quantity'   => 2,
            ]);
        $itemId = $addRes->json('data.items.0.id');

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/v1/cart/items/{$itemId}", [
                'quantity' => 0,
            ]);

        $res->assertStatus(422);
    }

    public function test_update_nonexistent_item_returns_404_and_does_not_create_cart(): void
    {
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson('/api/v1/cart/items/999999', [
                'quantity' => 5,
            ]);

        $res->assertStatus(404);
        $this->assertDatabaseMissing('carts', ['user_id' => $this->user->id]);
    }
}
