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

final class CartAddItemTest extends TestCase
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
            'email'             => 'cart_add_' . bin2hex(random_bytes(4)) . '@example.com',
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
            'slug' => 'cat-cart-' . bin2hex(random_bytes(4)),
            'name' => 'Cart Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-cart-' . bin2hex(random_bytes(4)),
            'name'        => 'Cart Product',
            'status'      => 'published',
            'is_active'   => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-CART-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 49.99,
            'is_active'  => true,
        ]);

        Inventory::create([
            'variant_id'        => $this->variant->id,
            'quantity_on_hand'  => 100,
            'quantity_reserved' => 0,
            'allow_backorder'   => false,
        ]);
    }

    public function test_add_item_lazily_creates_cart_and_adds_variant(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $this->variant->id,
                'quantity'   => 2,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.total_quantity', 2)
            ->assertJsonPath('data.subtotal', '99.98')
            ->assertJsonPath('data.items.0.variant_id', $this->variant->id)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', '49.99')
            ->assertJsonPath('data.items.0.line_subtotal', '99.98');
    }

    public function test_add_item_quantity_is_additive(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $this->variant->id,
                'quantity'   => 2,
            ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $this->variant->id,
                'quantity'   => 3,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.total_quantity', 5)
            ->assertJsonPath('data.subtotal', '249.95')
            ->assertJsonPath('data.items.0.quantity', 5);
    }

    public function test_add_item_with_zero_or_negative_quantity_fails_validation(): void
    {
        $responseZero = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $this->variant->id,
                'quantity'   => 0,
            ]);

        $responseZero->assertStatus(422);

        $responseNeg = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $this->variant->id,
                'quantity'   => -1,
            ]);

        $responseNeg->assertStatus(422);
    }
}
