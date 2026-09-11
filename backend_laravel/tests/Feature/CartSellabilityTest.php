<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CartSellabilityTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::where('name', 'customer')->first() ?? Role::first();
        $this->user = User::create([
            'email'             => 'cart_sell_' . bin2hex(random_bytes(4)) . '@example.com',
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

    public function test_reject_inactive_variant(): void
    {
        $category = Category::create(['slug' => 'cat-sell-1-' . bin2hex(random_bytes(4)), 'name' => 'Category']);
        $product = Product::create(['category_id' => $category->id, 'slug' => 'prod-sell-1-' . bin2hex(random_bytes(4)), 'name' => 'Product', 'status' => 'published', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-SELL-1', 'price' => 10.00, 'is_active' => false]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1]);

        $response->assertStatus(422);
    }

    public function test_reject_unpublished_product_variant(): void
    {
        $category = Category::create(['slug' => 'cat-sell-2-' . bin2hex(random_bytes(4)), 'name' => 'Category']);
        $product = Product::create(['category_id' => $category->id, 'slug' => 'prod-sell-2-' . bin2hex(random_bytes(4)), 'name' => 'Product', 'status' => 'draft', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-SELL-2', 'price' => 10.00, 'is_active' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1]);

        $response->assertStatus(422);
    }
}
