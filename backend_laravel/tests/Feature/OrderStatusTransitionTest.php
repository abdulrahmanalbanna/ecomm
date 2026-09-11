<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use App\Modules\Orders\Infrastructure\Persistence\Models\OrderEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrdersTestHelpers;
use Tests\TestCase;

/**
 * Admin status transitions — the order state machine.
 *
 * The PHP adjacency map mirrors fn_validate_order_status_transition(); the
 * PostgreSQL trigger trg_orders_status_transition is authoritative. Illegal
 * transitions must return 422 and leave the order (and inventory) untouched.
 */
final class OrderStatusTransitionTest extends TestCase
{
    use DatabaseTransactions;
    use OrdersTestHelpers;

    public function test_happy_path_through_the_full_lifecycle(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $variant = $this->makeSellableVariant('30.00', onHand: 20);
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 3]]);

        $steps = [
            'payment_pending' => ['confirmed_at' => false, 'reserved' => 3],
            'confirmed'       => ['confirmed_at' => true, 'reserved' => 3],
            'processing'      => ['confirmed_at' => true, 'reserved' => 3],
            'shipped'         => ['confirmed_at' => true, 'reserved' => 3],
            'delivered'       => ['confirmed_at' => true, 'reserved' => 0],
        ];

        foreach ($steps as $status => $expectations) {
            $response = $this->adminTransition($staffToken, $publicId, $status);
            $response->assertStatus(200)->assertJsonPath('data.status', $status);
            $this->assertSame($expectations['reserved'], $this->inventoryRow($variant->id)['quantity_reserved'], "reserved after {$status}");
        }

        $order = Order::where('public_id', $publicId)->firstOrFail();
        $this->assertNotNull($order->confirmed_at, 'DB trigger sets confirmed_at');
        $this->assertNotNull($order->shipped_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertNull($order->cancelled_at);

        // Delivered deducts physical stock (fn_deduct_inventory): on-hand 20-3.
        $row = $this->inventoryRow($variant->id);
        $this->assertSame(17, $row['quantity_on_hand']);
        $this->assertSame(0, $row['quantity_reserved']);
        $this->assertSame(17, $row['quantity_available']);

        // pending + 5 transitions = 6 events, in order.
        $events = OrderEvent::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(6, $events);
        $this->assertSame(
            ['pending', 'payment_pending', 'confirmed', 'processing', 'shipped', 'delivered'],
            $events->pluck('to_status')->all()
        );
        $this->assertNull($events->first()->from_status, 'Initial event has NULL from_status');
    }

    public function test_illegal_transition_is_rejected_and_state_unchanged(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $variant = $this->makeSellableVariant('10.00', onHand: 5);
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // pending → delivered is not in the adjacency map.
        $this->adminTransition($staffToken, $publicId, 'delivered')->assertStatus(422);

        $this->assertSame('pending', Order::where('public_id', $publicId)->value('status'));
        $this->assertSame(1, $this->inventoryRow($variant->id)['quantity_reserved'], 'No deduction side effect');
        $this->assertSame(5, $this->inventoryRow($variant->id)['quantity_on_hand']);
    }

    public function test_terminal_states_reject_every_transition(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $variant = $this->makeSellableVariant('10.00', onHand: 5);
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // Drive to cancelled (terminal).
        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/orders/{$publicId}/cancel", ['reason' => 'Fraud suspected'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        foreach (OrderStatus::all() as $target) {
            $this->adminTransition($staffToken, $publicId, $target)->assertStatus(422);
        }

        $this->assertSame('cancelled', Order::where('public_id', $publicId)->value('status'));
    }

    public function test_failed_transition_releases_reservations(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $variant = $this->makeSellableVariant('10.00', onHand: 8);
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 3]]);

        $this->adminTransition($staffToken, $publicId, 'payment_pending')->assertStatus(200);
        $this->adminTransition($staffToken, $publicId, 'failed')->assertStatus(200);

        $row = $this->inventoryRow($variant->id);
        $this->assertSame(0, $row['quantity_reserved'], 'failed releases reservations');
        $this->assertSame(8, $row['quantity_on_hand'], 'on-hand was never touched');
    }

    public function test_unknown_status_value_is_rejected_by_validation(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $variant = $this->makeSellableVariant('10.00');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->adminTransition($staffToken, $publicId, 'not_a_real_status')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_transition_note_is_recorded_on_event(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $staff = $this->makeStaff('staff');
        $staffToken = $this->issueToken($staff);

        $variant = $this->makeSellableVariant('10.00');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->adminTransition($staffToken, $publicId, 'payment_pending', ['note' => 'Awaiting bank transfer'])
            ->assertStatus(200);

        $order = Order::where('public_id', $publicId)->firstOrFail();
        $event = OrderEvent::where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame('Awaiting bank transfer', $event->note);
        $this->assertSame($staff->id, (int) $event->triggered_by);
    }

    public function test_direct_database_write_bypassing_app_is_rejected_by_trigger(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('10.00');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // The DB trigger is the authoritative guard even if application code
        // (or a rogue query) tries to skip states.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('orders')
            ->where('public_id', $publicId)
            ->update(['status' => OrderStatus::SHIPPED]);
    }

    public function test_transition_route_is_post_only(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $variant = $this->makeSellableVariant('10.00');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->authToken($staffToken)
            ->patchJson("/api/v1/admin/orders/{$publicId}/transition", ['status' => 'confirmed'])
            ->assertStatus(405);
    }
}
