<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrdersTestHelpers;
use Tests\TestCase;

/**
 * Customer order history listing: ownership scoping (application layer —
 * RLS is not part of this baseline), ordering, filters, pagination, shape.
 */
final class CustomerOrderListingTest extends TestCase
{
    use DatabaseTransactions;
    use OrdersTestHelpers;

    public function test_empty_history_returns_empty_list_with_meta(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        $response = $this->authToken($token)->getJson('/api/v1/customer/orders');

        $response->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_lists_only_own_orders_newest_first(): void
    {
        $userA = $this->makeCustomer();
        $tokenA = $this->issueToken($userA);
        $userB = $this->makeCustomer();
        $tokenB = $this->issueToken($userB);

        $variant = $this->makeSellableVariant('10.00');

        $older = $this->placeOrder($userA, $tokenA, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $newer = $this->placeOrder($userA, $tokenA, [['variant_id' => $variant->id, 'quantity' => 2]]);
        $otherUserOrder = $this->placeOrder($userB, $tokenB, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // Force deterministic placed_at ordering (both checkouts land in the same second otherwise).
        DB::table('orders')->where('public_id', $older)->update(['placed_at' => now()->subDay()]);
        DB::table('orders')->where('public_id', $newer)->update(['placed_at' => now()]);

        $response = $this->authToken($tokenA)->getJson('/api/v1/customer/orders');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 2);

        $ids = array_column($response->json('data'), 'public_id');
        $this->assertSame([$newer, $older], $ids, 'Newest first');
        $this->assertNotContains($otherUserOrder, $ids, 'Another customer order must never appear');
    }

    public function test_status_filter(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $staffToken = $this->issueToken($this->makeStaff('staff'));

        $variant = $this->makeSellableVariant('12.50');

        $pending = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $moved = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->adminTransition($staffToken, $moved, 'payment_pending')->assertStatus(200);

        $this->authToken($token)
            ->getJson('/api/v1/customer/orders?status=pending')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.public_id', $pending);

        $this->authToken($token)
            ->getJson('/api/v1/customer/orders?status=payment_pending')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.public_id', $moved);
    }

    public function test_date_filters(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('9.00');

        $old = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $recent = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        DB::table('orders')->where('public_id', $old)->update(['placed_at' => '2020-01-01 00:00:00+00']);

        $this->authToken($token)
            ->getJson('/api/v1/customer/orders?date_from=2025-01-01')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.public_id', $recent);

        $this->authToken($token)
            ->getJson('/api/v1/customer/orders?date_to=2021-01-01')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.public_id', $old);
    }

    public function test_pagination(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('4.00');

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);
        }

        $page1 = $this->authToken($token)->getJson('/api/v1/customer/orders?per_page=2&page=1');
        $page1->assertStatus(200)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(2, 'data');

        $page2 = $this->authToken($token)->getJson('/api/v1/customer/orders?per_page=2&page=2');
        $page2->assertStatus(200)->assertJsonCount(1, 'data');

        $all = array_merge($page1->json('data'), $page2->json('data'));
        $this->assertEqualsCanonicalizing(
            $ids,
            array_column($all, 'public_id'),
            'Pages must cover every order exactly once'
        );
    }

    public function test_listing_exposes_public_id_and_decimal_strings_not_internal_id(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('19.99');

        $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 2]]);

        $response = $this->authToken($token)->getJson('/api/v1/customer/orders');
        $response->assertStatus(200);

        $order = $response->json('data.0');
        $this->assertArrayNotHasKey('id', $order, 'Internal BIGINT id must never leak to customers');
        $this->assertSame($publicId, $order['public_id']);
        $this->assertSame('39.98', $order['total_amount'], 'Money serialized as exact decimal string');
        $this->assertIsString($order['total_amount']);
        $this->assertSame(1, $order['items_count'], 'One line item (quantity 2)');
    }

    public function test_status_filter_cannot_escape_ownership_scope(): void
    {
        $victim = $this->makeCustomer();
        $victimToken = $this->issueToken($victim);
        $attacker = $this->makeCustomer();
        $attackerToken = $this->issueToken($attacker);

        $variant = $this->makeSellableVariant('5.00');
        $this->placeOrder($victim, $victimToken, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->authToken($attackerToken)
            ->getJson('/api/v1/customer/orders?status=pending')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0);
    }
}
