<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\Actions\CheckoutAction;
use App\Modules\Orders\Application\DTOs\CheckoutData;
use App\Modules\Orders\Domain\Exceptions\EmptyCartCheckoutException;
use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrdersTestHelpers;
use Tests\TestCase;

/**
 * Concurrency-safe checkout.
 *
 * Follows the established sequential-serialization simulation pattern used
 * by CartConcurrencyTest / InventoryConcurrencyTest: PostgreSQL row locks
 * (cart FOR UPDATE, inventory FOR UPDATE inside fn_reserve_inventory_batch)
 * serialize conflicting transactions, so replaying the same intent
 * sequentially reproduces the exact outcome the second transaction would
 * observe after waiting on the lock.
 */
final class CheckoutConcurrencyTest extends TestCase
{
    use DatabaseTransactions;
    use OrdersTestHelpers;

    public function test_duplicate_checkout_of_same_cart_creates_only_one_order(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('25.00', onHand: 10);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 2]]);

        // First checkout wins.
        $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ])->assertStatus(201);

        // Replay (double-click / retry storm): cart is empty → rejected.
        $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ])->assertStatus(422)->assertJsonPath('message', 'Your cart is empty. Add items before checking out.');

        $this->assertSame(1, Order::where('user_id', $user->id)->count());
        $this->assertSame(2, $this->inventoryRow($variant->id)['quantity_reserved']);
    }

    public function test_inventory_race_serializes_and_prevents_overselling(): void
    {
        $variant = $this->makeSellableVariant('30.00', onHand: 5);

        $alice = $this->makeCustomer();
        $bob = $this->makeCustomer();
        $aliceToken = $this->issueToken($alice);
        $bobToken = $this->issueToken($bob);
        $aliceAddress = $this->makeAddress($alice);
        $bobAddress = $this->makeAddress($bob);

        // Both carts hold the last 5 units.
        $this->seedCart($alice, [['variant_id' => $variant->id, 'quantity' => 5]]);
        $this->seedCart($bob, [['variant_id' => $variant->id, 'quantity' => 5]]);

        // Alice checks out first and takes the stock.
        $this->authToken($aliceToken)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $aliceAddress->id,
        ])->assertStatus(201);

        // Bob's checkout (which would have blocked on the inventory row lock
        // in a true race) now finds nothing available.
        $this->authToken($bobToken)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $bobAddress->id,
        ])->assertStatus(409);

        $inv = $this->inventoryRow($variant->id);
        $this->assertSame(5, $inv['quantity_on_hand']);
        $this->assertSame(5, $inv['quantity_reserved'], 'No overselling: reserved never exceeds on-hand.');
        $this->assertSame(0, $inv['quantity_available']);

        // Bob's cart is preserved; only Alice got an order.
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('cart_items')->where('variant_id', $variant->id)->count());
    }

    public function test_partial_stock_race_grants_only_available_quantity(): void
    {
        $variant = $this->makeSellableVariant('10.00', onHand: 7);

        $a = $this->makeCustomer();
        $b = $this->makeCustomer();
        $ta = $this->issueToken($a);
        $tb = $this->issueToken($b);
        $aa = $this->makeAddress($a);
        $ab = $this->makeAddress($b);

        $this->seedCart($a, [['variant_id' => $variant->id, 'quantity' => 4]]);
        $this->seedCart($b, [['variant_id' => $variant->id, 'quantity' => 4]]);

        $this->authToken($ta)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $aa->id,
        ])->assertStatus(201);

        // 3 left; second order wants 4 → conflict, stock untouched by loser.
        $this->authToken($tb)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $ab->id,
        ])->assertStatus(409);

        $this->assertSame(4, $this->inventoryRow($variant->id)['quantity_reserved']);
        $this->assertSame(1, Order::count());
    }

    public function test_cart_modification_during_checkout_is_observed_at_lock_time(): void
    {
        $user = $this->makeCustomer();
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('10.00', onHand: 10);

        $cartId = $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // Simulate the interleaving: a concurrent request removes the item
        // (its own transaction) before checkout acquires the cart lock.
        // Checkout must see the REMOVED state, not a stale snapshot.
        DB::table('cart_items')->where('cart_id', $cartId)->delete();

        $this->expectException(EmptyCartCheckoutException::class);
        app(CheckoutAction::class)->execute(new CheckoutData(
            user: $user,
            shippingAddressId: $address->id,
        ));
    }

    public function test_quantity_change_during_checkout_is_billed_at_checkout(): void
    {
        $user = $this->makeCustomer();
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('10.00', onHand: 10);

        $cartId = $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // Concurrent PATCH bumps quantity to 3 before checkout locks the cart.
        DB::table('cart_items')->where('cart_id', $cartId)->update(['quantity' => 3]);

        $order = app(CheckoutAction::class)->execute(new CheckoutData(
            user: $user,
            shippingAddressId: $address->id,
        ));

        $this->assertSame('30.00', (string) $order->total_amount);
        $this->assertSame(3, (int) $order->items->first()->quantity);
        $this->assertSame(3, $this->inventoryRow($variant->id)['quantity_reserved']);
    }
}
