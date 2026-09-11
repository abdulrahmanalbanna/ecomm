<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrdersTestHelpers;
use Tests\TestCase;

/**
 * Checkout input validation + sellability re-checks at order placement time.
 */
final class CheckoutValidationTest extends TestCase
{
    use DatabaseTransactions;
    use OrdersTestHelpers;

    public function test_checkout_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => 1,
        ]);

        $response->assertStatus(401);
    }

    public function test_shipping_address_id_is_required(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('shipping_address_id');
    }

    public function test_notes_must_be_string_and_bounded(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
            'notes'               => str_repeat('x', 2001),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('notes');
    }

    public function test_empty_cart_cannot_be_checked_out(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);

        // No cart at all.
        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);
        $response->assertStatus(422)->assertJsonPath('message', 'Your cart is empty. Add items before checking out.');

        // Cart exists but has zero items.
        DB::table('carts')->insert([
            'user_id'    => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);
        $response->assertStatus(422);
    }

    public function test_inactive_variant_blocks_checkout_and_rolls_back(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('10.00', onHand: 10);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // Deactivate AFTER the item is in the cart.
        DB::table('product_variants')->where('id', $variant->id)->update(['is_active' => false]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('orders')->count());
        // Cart untouched on failure.
        $this->assertSame(1, DB::table('cart_items')->count());
    }

    public function test_unpublished_product_blocks_checkout(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        // Created unpublished from the start: the DB trigger
        // fn_validate_product_status_transition forbids published -> draft.
        $variant = $this->makeSellableVariant('10.00', onHand: 10, productOverrides: ['status' => 'draft']);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_soft_deleted_variant_blocks_checkout(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('10.00', onHand: 10);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        DB::table('product_variants')->where('id', $variant->id)->update(['deleted_at' => now()]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_variant_with_zero_price_blocks_checkout(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('10.00', onHand: 10);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        DB::table('product_variants')->where('id', $variant->id)->update(['price' => '0.00']);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('orders')->count());
    }

    // NOTE: a "dangling cart item" scenario cannot be constructed because
    // cart_items.variant_id has an FK to product_variants (ON DELETE CASCADE),
    // so the DB guarantees the row disappears with the variant. CheckoutAction
    // still carries a defensive 'product no longer exists' branch.

    public function test_failed_checkout_preserves_cart_for_retry(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        $good = $this->makeSellableVariant('10.00', onHand: 10);
        $bad = $this->makeSellableVariant('20.00', onHand: 10);

        $this->seedCart($user, [
            ['variant_id' => $good->id, 'quantity' => 1],
            ['variant_id' => $bad->id, 'quantity' => 1],
        ]);

        DB::table('product_variants')->where('id', $bad->id)->update(['is_active' => false]);

        $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ])->assertStatus(422);

        // Both items still in cart.
        $this->assertSame(2, DB::table('cart_items')->count());

        // Fix the catalog and retry succeeds.
        /** @var ProductVariant $fresh */
        $fresh = $this->makeSellableVariant('20.00', onHand: 10);
        DB::table('cart_items')->where('variant_id', $bad->id)->update(['variant_id' => $fresh->id]);

        $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ])->assertStatus(201);
    }
}
