<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Cart\Application\DTOs\AddCartItemData;
use App\Modules\Cart\Application\Services\CartService;
use App\Modules\Cart\Infrastructure\Persistence\Models\Cart;
use App\Modules\Cart\Infrastructure\Persistence\Models\CartItem;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Modules\Inventory\Infrastructure\Persistence\Models\Inventory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CartConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected ProductVariant $variant;
    protected CartService $cartService;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::where('name', 'customer')->first() ?? Role::first();
        $this->user = User::create([
            'email'             => 'cart_conc_' . bin2hex(random_bytes(4)) . '@example.com',
            'password_hash'     => Hash::make('password123'),
            'role_id'           => $role->id,
            'is_active'         => true,
            'is_email_verified' => true,
        ]);

        $category = Category::create(['slug' => 'cat-conc-' . bin2hex(random_bytes(4)), 'name' => 'Category']);
        $product = Product::create(['category_id' => $category->id, 'slug' => 'prod-conc-' . bin2hex(random_bytes(4)), 'name' => 'Product', 'status' => 'published', 'is_active' => true]);
        $this->variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-CONC-1', 'price' => 10.00, 'is_active' => true]);

        Inventory::create([
            'variant_id'        => $this->variant->id,
            'quantity_on_hand'  => 100,
            'quantity_reserved' => 0,
            'allow_backorder'   => false,
        ]);

        $this->cartService = app(CartService::class);
    }

    public function test_unique_user_id_constraint_enforcement_and_race_recovery(): void
    {
        // 1. Manually create cart
        $cart1 = Cart::create([
            'user_id'    => $this->user->id,
            'expires_at' => now()->addDays(30),
        ]);

        // 2. Attempt duplicate creation directly at database level -> raises 23505 QueryException
        $this->expectException(QueryException::class);
        Cart::create([
            'user_id'    => $this->user->id,
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function test_service_get_or_create_cart_recovers_from_unique_constraint_race(): void
    {
        $cart1 = $this->cartService->getOrCreateCart($this->user);

        // Calling getOrCreateCart again returns the exact same cart instance
        $cart2 = $this->cartService->getOrCreateCart($this->user);

        $this->assertEquals($cart1->id, $cart2->id);
        $this->assertEquals(1, Cart::where('user_id', $this->user->id)->count());
    }

    public function test_unique_cart_variant_constraint_enforcement_and_additive_recovery(): void
    {
        $cart = $this->cartService->getOrCreateCart($this->user);

        // Adding same variant twice updates quantity additively without creating duplicate rows
        $this->cartService->addItem($this->user, new AddCartItemData($this->variant->id, 2));
        $this->cartService->addItem($this->user, new AddCartItemData($this->variant->id, 3));

        $this->assertEquals(1, CartItem::where('cart_id', $cart->id)->where('variant_id', $this->variant->id)->count());
        $this->assertEquals(5, CartItem::where('cart_id', $cart->id)->where('variant_id', $this->variant->id)->value('quantity'));
    }
}
