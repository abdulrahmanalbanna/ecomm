<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrdersTestHelpers;
use Tests\TestCase;

/**
 * Inventory integration during checkout.
 *
 * Reservations go through the Inventory module's ONLY mechanism
 * (fn_reserve_inventory_batch). Insufficient stock must abort the whole
 * checkout atomically — no order, no line items, no reservation movements,
 * and the cart must remain intact for the customer to adjust quantities.
 */
final class CheckoutInventoryTest extends TestCase
{
    use DatabaseTransactions;
    use OrdersTestHelpers;

    public function test_insufficient_stock_rejects_checkout_with_conflict(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('15.00', onHand: 2);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 5]]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);

        $response->assertStatus(409);

        // Full rollback (scoped to this test's user/variant to stay robust
        // against committed data from other suites in the shared testing DB).
        $this->assertSame(0, Order::where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('order_events')->where('triggered_by', $user->id)->count());
        $this->assertSame(
            0,
            DB::table('inventory_movements')->where('variant_id', $variant->id)->where('movement_type', 'reservation')->count()
        );

        // Inventory untouched, cart preserved.
        $inv = $this->inventoryRow($variant->id);
        $this->assertSame(2, $inv['quantity_on_hand']);
        $this->assertSame(0, $inv['quantity_reserved']);
        $this->assertSame(1, DB::table('cart_items')->count());
    }

    public function test_reservation_reduces_available_without_touching_on_hand(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('10.00', onHand: 10);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 4]]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);
        $response->assertStatus(201);

        $inv = $this->inventoryRow($variant->id);
        $this->assertSame(10, $inv['quantity_on_hand'], 'Physical stock must not change at checkout.');
        $this->assertSame(4, $inv['quantity_reserved']);
        $this->assertSame(6, $inv['quantity_available']);

        // Ledger row written by the DB function, linked to the order.
        $movement = DB::table('inventory_movements')
            ->where('variant_id', $variant->id)
            ->where('movement_type', 'reservation')
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame(-4, (int) $movement->quantity_delta);
        $this->assertSame(10, (int) $movement->on_hand_after);
        $this->assertSame(Order::firstOrFail()->id, (int) $movement->order_id);
    }

    public function test_multi_item_checkout_reserves_every_variant(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);

        $a = $this->makeSellableVariant('7.50', onHand: 20);
        $b = $this->makeSellableVariant('12.25', onHand: 8);
        $c = $this->makeSellableVariant('3.00', onHand: 100);

        $this->seedCart($user, [
            ['variant_id' => $a->id, 'quantity' => 2],
            ['variant_id' => $b->id, 'quantity' => 3],
            ['variant_id' => $c->id, 'quantity' => 10],
        ]);

        $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        // 2*7.50 + 3*12.25 + 10*3.00 = 15.00 + 36.75 + 30.00 = 81.75
        ])->assertStatus(201)->assertJsonPath('data.subtotal', '81.75');

        $this->assertSame(2, $this->inventoryRow($a->id)['quantity_reserved']);
        $this->assertSame(3, $this->inventoryRow($b->id)['quantity_reserved']);
        $this->assertSame(10, $this->inventoryRow($c->id)['quantity_reserved']);
    }

    public function test_one_short_item_aborts_the_entire_multi_item_checkout(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);

        $plenty = $this->makeSellableVariant('7.50', onHand: 20);
        $short = $this->makeSellableVariant('12.25', onHand: 1);

        $this->seedCart($user, [
            ['variant_id' => $plenty->id, 'quantity' => 2],
            ['variant_id' => $short->id, 'quantity' => 5],
        ]);

        $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ])->assertStatus(409);

        // Even the in-stock variant must not be left reserved.
        $this->assertSame(0, $this->inventoryRow($plenty->id)['quantity_reserved']);
        $this->assertSame(0, $this->inventoryRow($short->id)['quantity_reserved']);
        $this->assertSame(0, Order::where('user_id', $user->id)->count());
        $this->assertSame(2, DB::table('cart_items')->count());
    }

    public function test_backorder_allows_checkout_beyond_available_stock(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('40.00', onHand: 0);

        DB::table('inventory')->where('variant_id', $variant->id)->update(['allow_backorder' => true]);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 3]]);

        $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ])->assertStatus(201);

        $inv = $this->inventoryRow($variant->id);
        $this->assertSame(0, $inv['quantity_on_hand']);
        $this->assertSame(3, $inv['quantity_reserved']);
    }

    public function test_variant_without_inventory_row_is_handled_without_crash(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('9.00', onHand: 5);

        // Remove the inventory row to exercise getOrCreateForVariant().
        DB::table('inventory')->where('variant_id', $variant->id)->delete();

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // The auto-created row starts at 0 on-hand with backorder disabled,
        // so the reservation conflicts (409) — but the request must not 500.
        $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ])->assertStatus(409);

        // Atomic rollback also removes the auto-created inventory row.
        $this->assertSame(0, DB::table('inventory')->where('variant_id', $variant->id)->count());
        $this->assertSame(0, Order::where('user_id', $user->id)->count());
    }
}
