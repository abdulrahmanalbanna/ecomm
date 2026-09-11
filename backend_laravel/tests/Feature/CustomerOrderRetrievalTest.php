<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\OrdersTestHelpers;
use Tests\TestCase;

/**
 * Customer order detail retrieval: full data, ownership scoping at the
 * application layer (404 not 403 — no existence leakage), UUID validation.
 */
final class CustomerOrderRetrievalTest extends TestCase
{
    use DatabaseTransactions;
    use OrdersTestHelpers;

    public function test_returns_full_order_detail_with_items_and_snapshots(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $address = $this->makeAddress($user, ['recipient_name' => 'Sara Alqahtani']);

        $variant = $this->makeSellableVariant('24.90');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 2]], $address);

        $response = $this->authToken($token)->getJson("/api/v1/customer/orders/{$publicId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.public_id', $publicId)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subtotal', '49.80')
            ->assertJsonPath('data.total_amount', '49.80')
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.items.0.sku', $variant->sku)
            ->assertJsonPath('data.items.0.unit_price', '24.90')
            ->assertJsonPath('data.items.0.total_price', '49.80')
            ->assertJsonPath('data.items.0.product_snapshot.variant_id', (string) $variant->id)
            ->assertJsonPath('data.shipping_address.recipient_name', 'Sara Alqahtani');

        $this->assertArrayNotHasKey('id', $response->json('data'));
    }

    public function test_other_customers_order_returns_404_not_403(): void
    {
        $owner = $this->makeCustomer();
        $ownerToken = $this->issueToken($owner);
        $intruder = $this->makeCustomer();
        $intruderToken = $this->issueToken($intruder);

        $variant = $this->makeSellableVariant('7.00');
        $publicId = $this->placeOrder($owner, $ownerToken, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // Application-layer ownership scoping: the query filters user_id, so
        // the resource simply does not exist for the intruder (404).
        $this->authToken($intruderToken)
            ->getJson("/api/v1/customer/orders/{$publicId}")
            ->assertStatus(404);
    }

    public function test_unknown_public_id_returns_404(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        $this->authToken($token)
            ->getJson('/api/v1/customer/orders/' . Str::uuid()->toString())
            ->assertStatus(404);
    }

    public function test_non_uuid_public_id_returns_404(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        // whereUuid constraint means the route does not match at all.
        $this->authToken($token)
            ->getJson('/api/v1/customer/orders/12345')
            ->assertStatus(404);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/customer/orders/' . Str::uuid()->toString())
            ->assertStatus(401);
    }

    public function test_detail_reflects_current_status_after_transition(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $variant = $this->makeSellableVariant('15.00');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->adminTransition($staffToken, $publicId, 'payment_pending')->assertStatus(200);

        $this->authToken($token)
            ->getJson("/api/v1/customer/orders/{$publicId}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'payment_pending');
    }

    public function test_internal_id_never_resolves_via_customer_route(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('3.00');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $order = Order::where('public_id', $publicId)->firstOrFail();

        // Numeric internal id must not be accepted as a route key.
        $this->authToken($token)
            ->getJson('/api/v1/customer/orders/' . $order->id)
            ->assertStatus(404);
    }
}
