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

final class CartAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $userA;
    protected string $tokenA;
    protected User $userB;
    protected string $tokenB;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::where('name', 'customer')->first() ?? Role::first();

        // User A
        $this->userA = User::create([
            'email'             => 'user_a_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => $role->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);
        $tokenA = 'token-a-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id'    => $this->userA->id,
            'token_hash' => hash('sha256', $tokenA),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
        $this->tokenA = $tokenA;

        // User B
        $this->userB = User::create([
            'email'             => 'user_b_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => $role->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);
        $tokenB = 'token-b-' . bin2hex(random_bytes(8));
        Session::create([
            'user_id'    => $this->userB->id,
            'token_hash' => hash('sha256', $tokenB),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);
        $this->tokenB = $tokenB;

        $category = Category::create([
            'slug' => 'cat-auth-' . bin2hex(random_bytes(4)),
            'name' => 'Auth Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-auth-' . bin2hex(random_bytes(4)),
            'name'        => 'Auth Product',
            'status'      => 'published',
            'is_active'   => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-AUTH-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 50.00,
            'is_active'  => true,
        ]);

        Inventory::create([
            'variant_id'        => $this->variant->id,
            'quantity_on_hand'  => 50,
            'quantity_reserved' => 0,
            'allow_backorder'   => false,
        ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/cart')->assertStatus(401);
        $this->postJson('/api/v1/cart/items', ['variant_id' => $this->variant->id, 'quantity' => 1])->assertStatus(401);
    }

    public function test_user_b_cannot_update_or_delete_user_a_cart_item(): void
    {
        // User A adds item
        $addRes = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $this->variant->id,
                'quantity'   => 2,
            ]);
        $itemAId = $addRes->json('data.items.0.id');

        // User B tries to update User A's item
        $updRes = $this->withHeader('Authorization', 'Bearer ' . $this->tokenB)
            ->patchJson("/api/v1/cart/items/{$itemAId}", ['quantity' => 10]);

        $updRes->assertStatus(404);

        // User B tries to delete User A's item
        $delRes = $this->withHeader('Authorization', 'Bearer ' . $this->tokenB)
            ->deleteJson("/api/v1/cart/items/{$itemAId}");

        $delRes->assertStatus(404);
    }
}
