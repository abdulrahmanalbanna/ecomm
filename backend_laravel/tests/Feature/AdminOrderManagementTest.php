<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use App\Modules\Orders\Infrastructure\Persistence\Models\OrderEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrdersTestHelpers;
use Tests\TestCase;

/**
 * Admin order management: cross-customer queue, search/filters, detail
 * shape (internal id + buyer), admin cancellation window, and the
 * delivered-transition stock deduction.
 */
final class AdminOrderManagementTest extends TestCase
{
    use DatabaseTransactions;
    use OrdersTestHelpers;

    public function test_admin_lists_orders_across_all_customers(): void
    {
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $alice = $this->makeCustomer();
        $bob = $this->makeCustomer();
        $variant = $this->makeSellableVariant('10.00');

        $aliceOrder = $this->placeOrder($alice, $this->issueToken($alice), [['variant_id' => $variant->id, 'quantity' => 1]]);
        $bobOrder = $this->placeOrder($bob, $this->issueToken($bob), [['variant_id' => $variant->id, 'quantity' => 2]]);

        $response = $this->authToken($staffToken)->getJson('/api/v1/admin/orders');

        $response->assertStatus(200)->assertJsonPath('meta.total', 2);

        $ids = array_column($response->json('data'), 'public_id');
        $this->assertEqualsCanonicalizing([$aliceOrder, $bobOrder], $ids);
    }

    public function test_search_by_sku_email_and_public_id(): void
    {
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('10.00');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $bySku = $this->authToken($staffToken)->getJson('/api/v1/admin/orders?search=' . $variant->sku);
        $bySku->assertStatus(200)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.public_id', $publicId);

        $byEmail = $this->authToken($staffToken)->getJson('/api/v1/admin/orders?search=' . $user->email);
        $byEmail->assertStatus(200)->assertJsonPath('meta.total', 1);

        $byPublic = $this->authToken($staffToken)->getJson('/api/v1/admin/orders?search=' . substr($publicId, 0, 8));
        $byPublic->assertStatus(200)->assertJsonPath('meta.total', 1);

        $noMatch = $this->authToken($staffToken)->getJson('/api/v1/admin/orders?search=zzz-no-such-term');
        $noMatch->assertStatus(200)->assertJsonPath('meta.total', 0);
    }

    public function test_status_filter_and_pagination_meta(): void
    {
        $staffToken = $this->issueToken($this->makeStaff('staff'));
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('6.00');

        $a = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->adminTransition($staffToken, $a, 'payment_pending')->assertStatus(200);

        $this->authToken($staffToken)
            ->getJson('/api/v1/admin/orders?status=payment_pending')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.public_id', $a);

        $this->authToken($staffToken)
            ->getJson('/api/v1/admin/orders?per_page=1&page=1')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_detail_exposes_internal_id_buyer_and_snapshots(): void
    {
        $staffToken = $this->issueToken($this->makeStaff('staff'));
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('13.45');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 2]]);

        $response = $this->authToken($staffToken)->getJson("/api/v1/admin/orders/{$publicId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.public_id', $publicId)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.subtotal', '26.90')
            ->assertJsonPath('data.items.0.sku', $variant->sku)
            ->assertJsonPath('data.items.0.product_snapshot.price', '13.45');

        $body = $response->json('data');
        $this->assertArrayHasKey('id', $body, 'Admin sees the internal id');
        $this->assertIsString($body['total_amount']);
        $this->assertNotNull($body['ip_address']);
    }

    public function test_unknown_order_returns_404_for_admin(): void
    {
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $this->authToken($staffToken)
            ->getJson('/api/v1/admin/orders/' . \Illuminate\Support\Str::uuid()->toString())
            ->assertStatus(404);
    }

    public function test_admin_can_cancel_confirmed_order_and_stock_is_released(): void
    {
        $staffToken = $this->issueToken($this->makeStaff('staff'));
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        $variant = $this->makeSellableVariant('10.00', onHand: 12);
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 5]]);

        $this->adminTransition($staffToken, $publicId, 'payment_pending')->assertStatus(200);
        $this->adminTransition($staffToken, $publicId, 'confirmed')->assertStatus(200);

        // Customer can no longer cancel, but admin can (confirmed is in the
        // DB cancellable set).
        $this->authToken($token)->postJson("/api/v1/customer/orders/{$publicId}/cancel")->assertStatus(422);

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/orders/{$publicId}/cancel", ['reason' => 'Out of stock'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $row = $this->inventoryRow($variant->id);
        $this->assertSame(0, $row['quantity_reserved']);
        $this->assertSame(12, $row['quantity_on_hand']);

        $order = Order::where('public_id', $publicId)->firstOrFail();
        $event = OrderEvent::where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame('confirmed', $event->from_status);
        $this->assertSame('cancelled', $event->to_status);
        $this->assertStringContainsString('Out of stock', (string) $event->note);
    }

    public function test_admin_cannot_cancel_shipped_order(): void
    {
        $staffToken = $this->issueToken($this->makeStaff('staff'));
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        $variant = $this->makeSellableVariant('10.00', onHand: 10);
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        foreach (['payment_pending', 'confirmed', 'processing', 'shipped'] as $status) {
            $this->adminTransition($staffToken, $publicId, $status)->assertStatus(200);
        }

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/orders/{$publicId}/cancel")
            ->assertStatus(422);

        $this->assertSame('shipped', Order::where('public_id', $publicId)->value('status'));
        $this->assertSame(1, $this->inventoryRow($variant->id)['quantity_reserved'], 'Reservation still held until delivered');
    }

    public function test_delivered_transition_deducts_stock_once(): void
    {
        $staffToken = $this->issueToken($this->makeStaff('staff'));
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        $variant = $this->makeSellableVariant('10.00', onHand: 9);
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 4]]);

        foreach (['payment_pending', 'confirmed', 'processing', 'shipped', 'delivered'] as $status) {
            $this->adminTransition($staffToken, $publicId, $status)->assertStatus(200);
        }

        $row = $this->inventoryRow($variant->id);
        $this->assertSame(5, $row['quantity_on_hand']);
        $this->assertSame(0, $row['quantity_reserved']);

        $saleMovements = DB::table('inventory_movements')
            ->where('variant_id', $variant->id)
            ->where('movement_type', 'sale')
            ->count();
        $this->assertSame(1, $saleMovements, 'Exactly one sale movement at delivered');
    }

    public function test_admin_cannot_transition_customer_order_to_illegal_state_via_api(): void
    {
        $staffToken = $this->issueToken($this->makeStaff('staff'));
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        $variant = $this->makeSellableVariant('10.00');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // pending → confirmed skips payment_pending (not allowed by the DB fn).
        $this->adminTransition($staffToken, $publicId, 'confirmed')->assertStatus(422);

        $this->assertSame('pending', Order::where('public_id', $publicId)->value('status'));
        $this->assertSame(1, OrderEvent::where('order_id', Order::where('public_id', $publicId)->value('id'))->count());
    }
}
