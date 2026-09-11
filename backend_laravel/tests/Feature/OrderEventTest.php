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
 * order_events — append-only lifecycle trail.
 *
 * Events are created by checkout and transitions, exposed via GET only,
 * and are never updated or deleted (no PUT/PATCH/DELETE routes exist; the
 * table is accessed through the partition parent only).
 */
final class OrderEventTest extends TestCase
{
    use DatabaseTransactions;
    use OrdersTestHelpers;

    public function test_checkout_creates_initial_event_with_null_from_status(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        $variant = $this->makeSellableVariant('11.00');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $order = Order::where('public_id', $publicId)->firstOrFail();
        $events = OrderEvent::where('order_id', $order->id)->orderBy('id')->get();

        $this->assertCount(1, $events);
        $this->assertNull($events[0]->from_status);
        $this->assertSame('pending', $events[0]->to_status);
        $this->assertSame($user->id, (int) $events[0]->triggered_by);
    }

    public function test_events_are_ordered_chronologically_with_actor_and_metadata(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $staff = $this->makeStaff('staff');
        $staffToken = $this->issueToken($staff);

        $variant = $this->makeSellableVariant('11.00');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->adminTransition($staffToken, $publicId, 'payment_pending')->assertStatus(200);
        $this->adminTransition($staffToken, $publicId, 'confirmed')->assertStatus(200);

        $response = $this->authToken($token)->getJson("/api/v1/customer/orders/{$publicId}/events");
        $response->assertStatus(200)->assertJsonCount(3, 'data');

        $events = $response->json('data');
        $this->assertNull($events[0]['from_status']);
        $this->assertSame('pending', $events[0]['to_status']);
        $this->assertSame('pending', $events[1]['from_status']);
        $this->assertSame('payment_pending', $events[1]['to_status']);
        $this->assertSame('payment_pending', $events[2]['from_status']);
        $this->assertSame('confirmed', $events[2]['to_status']);

        // Customer event attributed to the buyer; admin events to the staff user.
        $this->assertSame($user->id, $events[0]['triggered_by']);
        $this->assertSame($staff->id, $events[1]['triggered_by']);
        $this->assertSame('admin', $events[1]['metadata']['channel']);

        // IDs strictly increasing (append-only ordering).
        $ids = array_column($events, 'id');
        sort($ids);
        $this->assertSame($ids, array_column($events, 'id'));
    }

    public function test_admin_can_read_events_for_any_order(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $variant = $this->makeSellableVariant('11.00');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->authToken($staffToken)
            ->getJson("/api/v1/admin/orders/{$publicId}/events")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.to_status', 'pending');
    }

    public function test_events_are_immutable_no_update_or_delete_routes(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        $variant = $this->makeSellableVariant('11.00');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // No mutating verbs exist for the events collection.
        $this->authToken($token)->postJson("/api/v1/customer/orders/{$publicId}/events")->assertStatus(405);
        $this->authToken($token)->putJson("/api/v1/customer/orders/{$publicId}/events")->assertStatus(405);
        $this->authToken($token)->patchJson("/api/v1/customer/orders/{$publicId}/events")->assertStatus(405);
        $this->authToken($token)->deleteJson("/api/v1/customer/orders/{$publicId}/events")->assertStatus(405);
    }

    public function test_failed_transition_appends_no_event(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $variant = $this->makeSellableVariant('11.00');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $order = Order::where('public_id', $publicId)->firstOrFail();
        $before = OrderEvent::where('order_id', $order->id)->count();

        // Illegal: pending → shipped.
        $this->adminTransition($staffToken, $publicId, 'shipped')->assertStatus(422);

        $this->assertSame($before, OrderEvent::where('order_id', $order->id)->count(), 'Rejected transitions write nothing');
    }

    public function test_events_read_through_partition_parent(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        $variant = $this->makeSellableVariant('11.00');
        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $order = Order::where('public_id', $publicId)->firstOrFail();

        // The model/table used by the module is the partitioned parent.
        $this->assertSame('order_events', (new OrderEvent())->getTable());
        $this->assertSame(
            1,
            DB::table('order_events')->where('order_id', $order->id)->count(),
            'Parent aggregates rows from its partitions'
        );
    }

    public function test_customer_cannot_read_another_customers_events(): void
    {
        $owner = $this->makeCustomer();
        $ownerToken = $this->issueToken($owner);
        $intruder = $this->makeCustomer();
        $intruderToken = $this->issueToken($intruder);

        $variant = $this->makeSellableVariant('11.00');
        $publicId = $this->placeOrder($owner, $ownerToken, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->authToken($intruderToken)
            ->getJson("/api/v1/customer/orders/{$publicId}/events")
            ->assertStatus(404);
    }
}
