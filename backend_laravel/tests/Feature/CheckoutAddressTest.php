<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrdersTestHelpers;
use Tests\TestCase;

/**
 * Address handling during checkout: ownership enforcement (application-layer
 * scoping — RLS is not part of this baseline), snapshot immutability, and
 * the billing-address fallback policy.
 */
final class CheckoutAddressTest extends TestCase
{
    use DatabaseTransactions;
    use OrdersTestHelpers;

    public function test_billing_address_defaults_to_shipping_snapshot_when_omitted(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user, ['line1' => 'Shipping Lane 9']);
        $variant = $this->makeSellableVariant('10.00', onHand: 5);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.shipping_address.line1', 'Shipping Lane 9')
            ->assertJsonPath('data.billing_address.line1', 'Shipping Lane 9');

        $order = Order::firstOrFail();
        $this->assertSame($order->shipping_address, $order->billing_address);
    }

    public function test_separate_billing_address_is_snapshotted(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $shipping = $this->makeAddress($user, ['line1' => 'Ship Street', 'city' => 'Riyadh']);
        $billing = $this->makeAddress($user, ['line1' => 'Bill Street', 'city' => 'Dammam']);
        $variant = $this->makeSellableVariant('10.00', onHand: 5);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $shipping->id,
            'billing_address_id'  => $billing->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.shipping_address.city', 'Riyadh')
            ->assertJsonPath('data.billing_address.city', 'Dammam');
    }

    public function test_cannot_checkout_with_another_users_shipping_address(): void
    {
        $victim = $this->makeCustomer();
        $attacker = $this->makeCustomer();
        $token = $this->issueToken($attacker);
        $victimAddress = $this->makeAddress($victim);
        $variant = $this->makeSellableVariant('10.00', onHand: 5);

        $this->seedCart($attacker, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $victimAddress->id,
        ]);

        // Form-request ownership rule rejects it before the action runs.
        $response->assertStatus(422)
            ->assertJsonValidationErrors('shipping_address_id');

        $this->assertSame(0, Order::where('user_id', $attacker->id)->count());
        $this->assertSame(1, DB::table('cart_items')->count());
    }

    public function test_cannot_checkout_with_another_users_billing_address(): void
    {
        $victim = $this->makeCustomer();
        $attacker = $this->makeCustomer();
        $token = $this->issueToken($attacker);
        $ownAddress = $this->makeAddress($attacker);
        $victimAddress = $this->makeAddress($victim);
        $variant = $this->makeSellableVariant('10.00', onHand: 5);

        $this->seedCart($attacker, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $ownAddress->id,
            'billing_address_id'  => $victimAddress->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('billing_address_id');

        $this->assertSame(0, Order::where('user_id', $attacker->id)->count());
    }

    public function test_nonexistent_address_is_rejected(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('10.00', onHand: 5);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => 999999999,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('shipping_address_id');
    }

    public function test_snapshot_contains_full_address_fields(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user, [
            'recipient_name' => 'Khalid Al-Otaibi',
            'phone'          => '+966512345678',
            'line1'          => 'Olaya St 42',
            'line2'          => 'Floor 3',
            'city'           => 'Riyadh',
            'state'          => 'RR',
            'postal_code'    => '12211',
            'country_code'   => 'SA',
        ]);
        $variant = $this->makeSellableVariant('10.00', onHand: 5);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $response = $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ]);

        $snapshot = $response->json('data.shipping_address');
        $this->assertSame('Khalid Al-Otaibi', $snapshot['recipient_name']);
        $this->assertSame('+966512345678', $snapshot['phone']);
        $this->assertSame('Olaya St 42', $snapshot['line1']);
        $this->assertSame('Floor 3', $snapshot['line2']);
        $this->assertSame('Riyadh', $snapshot['city']);
        $this->assertSame('RR', $snapshot['state']);
        $this->assertSame('12211', $snapshot['postal_code']);
        $this->assertSame('SA', $snapshot['country_code']);
    }

    public function test_address_mutation_after_checkout_does_not_change_order(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user, ['line1' => 'Original Road']);
        $variant = $this->makeSellableVariant('10.00', onHand: 5);

        $this->seedCart($user, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->authToken($token)->postJson('/api/v1/customer/orders/checkout', [
            'shipping_address_id' => $address->id,
        ])->assertStatus(201);

        DB::table('addresses')->where('id', $address->id)->update(['line1' => 'Renamed Road']);

        $order = Order::firstOrFail();
        $this->assertSame('Original Road', $order->shipping_address['line1']);
    }
}
