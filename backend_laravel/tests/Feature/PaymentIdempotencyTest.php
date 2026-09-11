<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentsTestHelpers;
use Tests\TestCase;

/**
 * Idempotency guarantees for payment intents:
 *  - same key + same order → replay of the existing payment (no second charge)
 *  - same key + different order → 409 conflict
 *  - second intent for the same order (no key) → 409 (UNIQUE order_id)
 *  - the database itself rejects duplicate idempotency keys (23505)
 */
final class PaymentIdempotencyTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentsTestHelpers;

    public function test_same_idempotency_key_and_order_replays_the_existing_payment(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('100.00', 5);
        $orderPublicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response($this->tabbyPending('tabby_sess_replay'))]);

        $first = $this->createIntent($user, $token, $orderPublicId, ['idempotency_key' => 'idem-key-replay-1'])
            ->assertStatus(201)
            ->json('data.public_id');

        $second = $this->createIntent($user, $token, $orderPublicId, ['idempotency_key' => 'idem-key-replay-1'])
            ->assertStatus(201)
            ->json('data.public_id');

        $this->assertSame($first, $second);

        // Exactly one payment row — the replay did NOT re-call the gateway.
        $this->assertSame(1, (int) DB::table('payments')->where('order_id', DB::table('orders')->where('public_id', $orderPublicId)->value('id'))->count());
        $this->assertSame(1, count(Http::recorded()));
    }

    public function test_same_key_with_different_order_is_a_conflict(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('100.00', 5);

        $orderA = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);
        $orderB = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response($this->tabbyPending())]);

        $this->createIntent($user, $token, $orderA, ['idempotency_key' => 'idem-key-shared-1'])
            ->assertStatus(201);

        $this->createIntent($user, $token, $orderB, ['idempotency_key' => 'idem-key-shared-1'])
            ->assertStatus(409);
    }

    public function test_second_intent_for_same_order_without_key_conflicts(): void
    {
        $fixture = $this->makeProcessingPayment('tabby');

        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response($this->tabbyPending('tabby_sess_second'))]);

        $this->createIntent($fixture['user'], $fixture['token'], $fixture['order_public_id'])
            ->assertStatus(409);

        // payments.order_id UNIQUE is the final boundary: still one payment.
        $this->assertSame(
            1,
            (int) DB::table('payments')->where('order_id', $fixture['payment']->order_id)->count(),
        );
    }

    public function test_database_rejects_duplicate_idempotency_keys(): void
    {
        $fixture = $this->makeProcessingPayment('tabby');

        $existing = DB::table('payments')->where('id', $fixture['payment']->id)->first();

        // A second, unpaid order for the same customer.
        $variant = $this->makeSellableVariant('40.00', 5);
        $otherOrder = $this->placeOrder($fixture['user'], $fixture['token'], [['variant_id' => $variant->id, 'quantity' => 1]]);
        $otherOrderId = (int) DB::table('orders')->where('public_id', $otherOrder)->value('id');

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('payments')->insert([
            'order_id' => $otherOrderId,
            'gateway_id' => (int) $existing->gateway_id,
            'payment_method' => 'full',
            'amount' => '40.00',
            'currency' => 'SAR',
            'status' => 'pending',
            'idempotency_key' => (string) $existing->idempotency_key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
