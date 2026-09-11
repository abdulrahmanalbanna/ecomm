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

final class CartRemoveItemTest extends TestCase
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
            'email'             => 'cart_rem_' . bin2hex(random_bytes(4)) . '@example.com',
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
            'slug' => 'cat-rem-' . bin2hex(random_bytes(4)),
            'name' => 'Remove Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'slug'        => 'prod-rem-' . bin2hex(random_bytes(4)),
            'name'        => 'Remove Product',
            'status'      => 'published',
            'is_active'   => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-REM-' . strtoupper(bin2hex(random_bytes(3))),
            'price'      => 30.00,
            'is_active'  => true,
        ]);

        Inventory::create([
            'variant_id'        => $this->variant->id,
            'quantity_on_hand'  => 20,
            'quantity_reserved' => 0,
            'allow_backorder'   => false,
        ]);
    }

    public function test_remove_item_deletes_specified_item_only(): void
    {
        $addRes = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $this->variant->id,
                'quantity'   => 2,
            ]);
        $itemId = $addRes->json('data.items.0.id');

        $delRes = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson("/api/v1/cart/items/{$itemId}");

        $delRes->assertStatus(200)
            ->assertJsonPath('data.items_count', 0)
            ->assertJsonPath('data.total_quantity', 0)
            ->assertJsonPath('data.subtotal', '0.00');

        $this->assertDatabaseMissing('cart_items', ['id' => $itemId]);
    }

    public function test_remove_item_when_no_cart_exists_returns_404_without_creating_cart(): void
    {
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->deleteJson('/api/v1/cart/items/999999');

        $res->assertStatus(404);
        $this->assertDatabaseMissing('carts', ['user_id' => $this->user->id]);
    }
}
