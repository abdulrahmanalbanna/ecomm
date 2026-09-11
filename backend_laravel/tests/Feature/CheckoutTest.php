<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use App\Modules\Orders\Infrastructure\Persistence\Models\OrderEvent;
use App\Modules\Orders\Infrastructure\Persistence\Models\OrderItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrdersTestHelpers;
use Tests\TestCase;

/**
 * Core checkout happy path: order creation, snapshots, server-computed
 * totals, inventory reservation, cart clearing, initial lifecycle event.
 */
final class CheckoutTest extends TestCase
{
    use DatabaseTransactions;
    use OrdersTestHelpers;

    public function test_successful_checkout_creates_order_with_items_and_totals(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);

        $v1 = $this->makeSellableVariant('19.99', onHand: 50);
        $v2 = $this->makeSellableVariant('5.00', onHand: 5);

        $this->seedCart($user, [
            ['variant_id' => $v1->id, 'quantity' => 3],
            ['variant_id' => $v2->id, 'quantity' => 2],
        ]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);

        // subtotal = 3*19.99 + 2*5.00 = 59.97 + 10.00 = 69.97
        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.subtotal', '69.97')
            ->assertJsonPath('data.discount_amount', '0.00')
            ->assertJsonPath('data.shipping_amount', '0.00')
            ->assertJsonPath('data.tax_amount', '0.00')
            ->assertJsonPath('data.total_amount', '69.97')
            ->assertJsonPath('data.items_count', 2);

        $publicId = $response->json('data.public_id');
        $this->assertNotEmpty($publicId);

        $order = Order::where('public_id', $publicId)->firstOrFail();
        $this->assertSame($user->id, $order->user_id);
        $this->assertSame('pending', $order->status);
        $this->assertNotNull($order->placed_at);

        // Internal id must never leak into the customer payload.
        $response->assertJsonMissingPath('data.id');

        // Line items with exact decimal strings.
        $items = OrderItem::where('order_id', $order->id)->orderBy('variant_id')->get();
        $this->assertCount(2, $items);
        $this->assertSame('59.97', (string) $items[0]->total_price);
        $this->assertSame('10.00', (string) $items[1]->total_price);
        $this->assertSame('19.99', (string) $items[0]->unit_price);

        // Cart is cleared.
        $this->assertSame(0, DB::table('cart_items')->where('variant_id', $v1->id)->count());
        $this->assertSame(0, DB::table('cart_items')->where('variant_id', $v2->id)->count());

        // Inventory reserved (not deducted).
        $inv1 = $this->inventoryRow($v1->id);
        $this->assertSame(50, $inv1['quantity_on_hand']);
        $this->assertSame(3, $inv1['quantity_reserved']);
        $this->assertSame(47, $inv1['quantity_available']);

        $inv2 = $this->inventoryRow($v2->id);
        $this->assertSame(2, $inv2['quantity_reserved']);

        // Reservation movements exist and are linked to the order.
        $movements = DB::table('inventory_movements')
            ->where('movement_type', 'reservation')
            ->where('order_id', $order->id)
            ->get();
        $this->assertCount(2, $movements);

        // Initial append-only event: NULL -> pending.
        $events = OrderEvent::where('order_id', $order->id)->get();
        $this->assertCount(1, $events);
        $this->assertNull($events[0]->from_status);
        $this->assertSame('pending', $events[0]->to_status);
        $this->assertSame($user->id, $events[0]->triggered_by);
    }

    public function test_checkout_uses_current_catalog_price_not_any_client_value(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('12.34', onHand: 10);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 2]]);

        // Client attempts to dictate pricing — must be ignored entirely.
        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
            'unit_price'          => '0.01',
            'total_amount'        => '0.02',
            'items'               => [['variant_id' => $variant->id, 'price' => 0.01]],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.subtotal', '24.68')
            ->assertJsonPath('data.total_amount', '24.68');

        $item = OrderItem::firstOrFail();
        $this->assertSame('12.34', (string) $item->unit_price);
    }

    public function test_price_change_after_cart_creation_is_reflected_in_order(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('100.00', onHand: 10);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // Merchant raises the price before checkout.
        DB::table('product_variants')->where('id', $variant->id)->update(['price' => '149.99']);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.total_amount', '149.99');
        $this->assertSame('149.99', (string) OrderItem::firstOrFail()->unit_price);
    }

    public function test_product_snapshot_is_immutable_after_catalog_changes(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('25.00', onHand: 10);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);
        $response->assertStatus(201);

        $order = Order::firstOrFail();
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $snapshot = $item->product_snapshot;

        $this->assertSame('25.00', $snapshot['price']);
        $this->assertSame($variant->sku, $snapshot['sku']);
        $this->assertSame($variant->name, $snapshot['name']);
        $this->assertArrayHasKey('captured_at', $snapshot);

        // Mutate the catalog aggressively.
        DB::table('product_variants')->where('id', $variant->id)->update([
            'price' => '999.99',
            'name'  => 'Renamed Variant',
            'sku'   => 'CHANGED-SKU',
        ]);

        $item->refresh();
        $this->assertSame('25.00', $item->product_snapshot['price'], 'Snapshot price must not change.');
        $this->assertSame($variant->sku, $item->product_snapshot['sku'], 'Snapshot sku must not change.');
        $this->assertSame('25.00', (string) $item->unit_price, 'unit_price must not change.');
    }

    public function test_address_snapshot_survives_address_deletion(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user, ['line1' => 'Old Street 1', 'city' => 'Jeddah']);
        $variant = $this->makeSellableVariant('10.00', onHand: 5);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);
        $response->assertStatus(201);
        $response->assertJsonPath('data.shipping_address.line1', 'Old Street 1');
        $response->assertJsonPath('data.shipping_address.city', 'Jeddah');

        // Addresses are hard-deleted in this baseline; the order keeps its snapshot.
        DB::table('addresses')->where('id', $address->id)->delete();

        $order = Order::firstOrFail();
        $this->assertSame('Old Street 1', $order->shipping_address['line1']);
        $this->assertSame('Jeddah', $order->shipping_address['city']);
    }

    public function test_checkout_records_coupon_code_from_cart(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('30.00', onHand: 5);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]], couponCode: 'RAMADAN10');

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.coupon_code', 'RAMADAN10');

        // Promotions are out of scope: the code is recorded but discount stays 0.00.
        $response->assertJsonPath('data.discount_amount', '0.00');
    }

    public function test_totals_satisfy_database_constraints_for_large_quantities(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user);
        $variant = $this->makeSellableVariant('0.01', onHand: 100000);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 99999]]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);

        // 99999 * 0.01 = 999.99 — exact, no float drift.
        $response->assertStatus(201)
            ->assertJsonPath('data.subtotal', '999.99')
            ->assertJsonPath('data.total_amount', '999.99');
    }
}
