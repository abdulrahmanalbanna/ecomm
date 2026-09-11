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
 * Customer self-cancellation. Allowed only within the pre-fulfillment
 * window (pending / payment_pending). Cancellation releases the order's
 * inventory reservations via the Inventory module (fn_release_inventory)
 * and appends exactly one lifecycle event.
 */
final class CustomerOrderCancellationTest extends TestCase
{
    use DatabaseTransactions;
    use OrdersTestHelpers;

    public function test_customer_can_cancel_pending_order_and_stock_is_released(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        $variant = $this->makeSellableVariant('20.00', onHand: 10);
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 4]]);

        // Checkout reserved 4 units.
        $this->assertSame(4, $this->inventoryRow($variant->id)['quantity_reserved']);

        $response = $this->authToken($token)->postJson("/api/v1/customer/orders/{$publicId}/cancel", [
            'reason' => 'Ordered the wrong size',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('message', 'Order cancelled successfully');

        $order = Order::where('public_id', $publicId)->firstOrFail();
        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull($order->cancelled_at, 'DB trigger sets the lifecycle timestamp');

        // Reservations released: reserved back to 0, on-hand untouched.
        $row = $this->inventoryRow($variant->id);
        $this->assertSame(0, $row['quantity_reserved']);
        $this->assertSame(10, $row['quantity_on_hand']);

        // A release movement was written by the Inventory module.
        $this->assertSame(
            1,
            DB::table('inventory_movements')
                ->where('variant_id', $variant->id)
                ->where('movement_type', 'release')
                ->count()
        );

        // Exactly one cancellation event appended.
        $event = OrderEvent::where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame('pending', $event->from_status);
        $this->assertSame('cancelled', $event->to_status);
        $this->assertSame($user->id, (int) $event->triggered_by);
        $this->assertStringContainsString('Ordered the wrong size', (string) $event->note);
    }

    public function test_payment_pending_order_is_cancellable(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $variant = $this->makeSellableVariant('8.00', onHand: 5);
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 2]]);

        $this->adminTransition($staffToken, $publicId, 'payment_pending')->assertStatus(200);

        $this->authToken($token)
            ->postJson("/api/v1/customer/orders/{$publicId}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, $this->inventoryRow($variant->id)['quantity_reserved']);
    }

    public function test_cannot_cancel_confirmed_order(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $variant = $this->makeSellableVariant('8.00', onHand: 5);
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->adminTransition($staffToken, $publicId, 'payment_pending')->assertStatus(200);
        $this->adminTransition($staffToken, $publicId, 'confirmed')->assertStatus(200);

        $this->authToken($token)
            ->postJson("/api/v1/customer/orders/{$publicId}/cancel")
            ->assertStatus(422);

        // Status untouched, reservations intact.
        $this->assertSame('confirmed', Order::where('public_id', $publicId)->value('status'));
        $this->assertSame(1, $this->inventoryRow($variant->id)['quantity_reserved']);
    }

    public function test_cannot_cancel_already_cancelled_order(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        $variant = $this->makeSellableVariant('8.00', onHand: 5);
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->authToken($token)->postJson("/api/v1/customer/orders/{$publicId}/cancel")->assertStatus(200);

        $this->authToken($token)
            ->postJson("/api/v1/customer/orders/{$publicId}/cancel")
            ->assertStatus(422);

        // Only one cancellation event exists (no double release).
        $order = Order::where('public_id', $publicId)->firstOrFail();
        $this->assertSame(
            1,
            OrderEvent::where('order_id', $order->id)->where('to_status', 'cancelled')->count()
        );
        $this->assertSame(
            1,
            DB::table('inventory_movements')->where('variant_id', $variant->id)->where('movement_type', 'release')->count()
        );
    }

    public function test_cannot_cancel_another_customers_order(): void
    {
        $owner = $this->makeCustomer();
        $ownerToken = $this->issueToken($owner);
        $intruder = $this->makeCustomer();
        $intruderToken = $this->issueToken($intruder);

        $variant = $this->makeSellableVariant('8.00', onHand: 5);
        $publicId = $this->placeOrder($owner, $ownerToken, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->authToken($intruderToken)
            ->postJson("/api/v1/customer/orders/{$publicId}/cancel")
            ->assertStatus(404);

        $this->assertSame('pending', Order::where('public_id', $publicId)->value('status'));
        $this->assertSame(1, $this->inventoryRow($variant->id)['quantity_reserved']);
    }

    public function test_reason_is_optional_and_length_bounded(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        $variant = $this->makeSellableVariant('8.00', onHand: 5);
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // Over-long reason fails validation.
        $this->authToken($token)
            ->postJson("/api/v1/customer/orders/{$publicId}/cancel", ['reason' => str_repeat('x', 1001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        // Omitted reason succeeds.
        $this->authToken($token)
            ->postJson("/api/v1/customer/orders/{$publicId}/cancel")
            ->assertStatus(200);
    }

    public function test_cancel_route_is_post_only(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        $variant = $this->makeSellableVariant('8.00', onHand: 5);
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->authToken($token)->deleteJson("/api/v1/customer/orders/{$publicId}/cancel")->assertStatus(405);
        $this->authToken($token)->putJson("/api/v1/customer/orders/{$publicId}/cancel")->assertStatus(405);
    }
}
