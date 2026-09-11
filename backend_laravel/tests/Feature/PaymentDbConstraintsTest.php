<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentsTestHelpers;
use Tests\TestCase;

/**
 * The PostgreSQL schema is the FINAL enforcement boundary for payments:
 * the status-transition trigger (P0005), the settled-without-reference CHECK,
 * the refund aggregate trigger, and the UNIQUE idempotency keys. These tests
 * bypass the application layer entirely (raw DB writes) to prove the
 * database rejects what the code promises it never does.
 */
final class PaymentDbConstraintsTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentsTestHelpers;

    public function test_trigger_rejects_illegal_status_transition_with_p0005(): void
    {
        $fixture = $this->makePaidPayment('tabby', '100.00');
        $payment = $fixture['payment'];

        try {
            // paid → pending is not in the adjacency map. The savepoint keeps
            // the outer DatabaseTransactions wrapper usable after the
            // deliberately-aborted statement.
            DB::transaction(function () use ($payment): void {
                DB::table('payments')->where('id', $payment->id)->update(['status' => 'pending']);
            });
            $this->fail('Expected the transition trigger to reject paid → pending.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Invalid payment status transition', $e->getMessage());
            $this->assertSame('P0005', (string) $e->errorInfo[0] ?? '');
        }

        // The row is untouched.
        $this->assertSame('paid', (string) $payment->fresh()->status);
    }

    public function test_trigger_is_a_noop_when_status_is_unchanged(): void
    {
        $fixture = $this->makePaidPayment('tabby', '100.00');
        $payment = $fixture['payment'];

        // Updating non-status columns must not trip the transition trigger.
        DB::table('payments')->where('id', $payment->id)->update([
            'gateway_response' => json_encode(['audit' => true]),
        ]);

        $this->assertSame('paid', (string) $payment->fresh()->status);
    }

    public function test_settled_status_requires_a_gateway_reference(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00');
        $payment = $fixture['payment'];

        try {
            // processing → paid is a LEGAL hop, but the CHECK constraint
            // forbids settling without the gateway reference.
            DB::table('payments')->where('id', $payment->id)->update([
                'status' => 'paid',
                'gateway_payment_id' => null,
            ]);
            $this->fail('Expected chk_payments_gateway_id_on_paid to reject the write.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('chk_payments_gateway_id_on_paid', $e->getMessage());
            $this->assertSame('23514', (string) $e->getCode());
        }
    }

    public function test_only_one_payment_intent_per_order(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00');
        $payment = $fixture['payment'];

        $gatewayId = (int) DB::table('payment_gateways')->where('code', 'tamara')->value('id');

        try {
            DB::table('payments')->insert([
                'order_id' => (int) $payment->order_id,
                'gateway_id' => $gatewayId,
                'payment_method' => 'full',
                'amount' => '100.00',
                'currency' => 'SAR',
                'status' => 'pending',
                'idempotency_key' => 'db_test_second_intent',
            ]);
            $this->fail('Expected payments_order_id_key to reject a second intent.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('payments_order_id_key', $e->getMessage());
            $this->assertSame('23505', (string) $e->getCode());
        }
    }

    public function test_payment_amount_must_be_positive(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00');
        $payment = $fixture['payment'];

        $gatewayId = (int) DB::table('payment_gateways')->where('code', 'tabby')->value('id');
        $otherOrder = (int) DB::table('orders')
            ->where('id', '!=', $payment->order_id)
            ->where('status', 'pending')
            ->value('id');

        if ($otherOrder === 0 || $otherOrder === null) {
            // Create a fresh order to attach the zero-amount intent to.
            $user = $this->makeCustomer();
            $token = $this->issueToken($user);
            $variant = $this->makeSellableVariant('50.00', 3);
            $publicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);
            $otherOrder = (int) DB::table('orders')->where('public_id', $publicId)->value('id');
        }

        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            'order_id' => $otherOrder,
            'gateway_id' => $gatewayId,
            'payment_method' => 'full',
            'amount' => '0.00',
            'currency' => 'SAR',
            'status' => 'pending',
            'idempotency_key' => 'db_test_zero_amount',
        ]);
    }

    public function test_attempt_numbers_are_unique_per_payment(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00');
        $payment = $fixture['payment'];

        $this->expectException(QueryException::class);

        // Attempt 1 already exists (created by the intent flow).
        DB::table('payment_attempts')->insert([
            'payment_id' => (int) $payment->id,
            'attempt_number' => 1,
            'amount' => '100.00',
            'currency' => 'SAR',
            'status' => 'pending',
        ]);
    }

    public function test_gateway_transaction_id_cannot_be_double_booked(): void
    {
        $fixture = $this->makePaidPayment('tabby', '100.00');
        $payment = $fixture['payment'];

        // The paid webhook already booked a charge with this gateway id.
        $existing = DB::table('payment_transactions')
            ->where('payment_id', $payment->id)
            ->where('transaction_type', 'charge')
            ->first();

        $this->assertNotNull($existing);

        $this->expectException(QueryException::class);

        DB::table('payment_transactions')->insert([
            'payment_id' => (int) $payment->id,
            'transaction_type' => 'charge',
            'gateway_transaction_id' => (string) $existing->gateway_transaction_id,
            'amount' => '100.00',
            'currency' => 'SAR',
            'settled_at' => now(),
        ]);
    }

    public function test_webhook_event_ids_are_unique(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00');

        $eventId = $this->uniqueEventId('dup');

        $this->postSignedWebhook('tabby', [
            'id' => $eventId,
            'event' => 'payment.failed',
            'payment_id' => $fixture['session_id'],
        ])->assertStatus(200);

        $gatewayId = (int) DB::table('payment_gateways')->where('code', 'tabby')->value('id');

        $this->expectException(QueryException::class);

        DB::table('payment_webhook_events')->insert([
            'gateway_id' => $gatewayId,
            'event_type' => 'payment.failed',
            'gateway_event_id' => $eventId,
            'payload' => json_encode(['id' => $eventId]),
        ]);
    }

    public function test_refund_aggregate_trigger_caps_total_at_payment_amount(): void
    {
        $fixture = $this->makePaidPayment('tabby', '100.00');
        $payment = $fixture['payment'];

        $chargeId = (int) DB::table('payment_transactions')
            ->where('payment_id', $payment->id)
            ->where('transaction_type', 'charge')
            ->value('id');

        // 60.00 fits.
        DB::table('refunds')->insert([
            'payment_id' => (int) $payment->id,
            'transaction_id' => $chargeId,
            'amount' => '60.00',
            'status' => 'processing',
            'gateway_refund_id' => 'db_test_ref_a',
        ]);

        try {
            // Another 60.00 would total 120.00 > 100.00. The savepoint keeps
            // the outer test transaction usable after the rejected insert.
            DB::transaction(function () use ($payment, $chargeId): void {
                DB::table('refunds')->insert([
                    'payment_id' => (int) $payment->id,
                    'transaction_id' => $chargeId,
                    'amount' => '60.00',
                    'status' => 'processing',
                    'gateway_refund_id' => 'db_test_ref_b',
                ]);
            });
            $this->fail('Expected the refund aggregate trigger to reject the over-refund.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Refund total', $e->getMessage());
            $this->assertStringContainsString('exceeds payment amount', $e->getMessage());
        }

        // Exactly at the cap is allowed (trigger rejects only >): a 40.00
        // refund fits alongside the in-flight 60.00 one.
        DB::table('refunds')->insert([
            'payment_id' => (int) $payment->id,
            'transaction_id' => $chargeId,
            'amount' => '40.00',
            'status' => 'processing',
            'gateway_refund_id' => 'db_test_ref_c',
        ]);

        $this->assertSame(2, DB::table('refunds')->where('payment_id', $payment->id)->count());
    }

    public function test_installment_plan_requires_an_installment_payment(): void
    {
        // A 'full' payment must never host a plan — the trigger forbids it
        // even for direct SQL writers.
        $fixture = $this->makeProcessingPayment('tabby', '100.00');

        $this->expectException(QueryException::class);

        DB::table('installment_plans')->insert([
            'payment_id' => (int) $fixture['payment']->id,
            'number_of_installments' => 4,
            'installment_amount' => '25.00',
            'first_payment_date' => now()->toDateString(),
            'frequency' => 'monthly',
            'status' => 'active',
        ]);
    }

    public function test_installment_gateway_transaction_id_is_unique_across_plans(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00', [
            'payment_method' => 'installment',
            'number_of_installments' => 4,
        ]);
        [, $staffToken] = $this->makePaymentStaff(['payments.view']);

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$fixture['payment']->public_id}/installments", [
                'number_of_installments' => 4,
            ])
            ->assertStatus(201);

        $planId = (int) DB::table('installment_plans')->where('payment_id', $fixture['payment']->id)->value('id');

        // Settle installment #1 with a gateway charge id. Updating the plan's
        // existing rows avoids colliding with uq_installment_number_per_plan.
        DB::table('installments')
            ->where('plan_id', $planId)
            ->where('installment_number', 1)
            ->update([
                'status' => 'paid',
                'gateway_transaction_id' => 'db_test_inst_txn',
                'paid_at' => now(),
            ]);

        $this->expectException(QueryException::class);

        // The same gateway charge cannot settle a second installment anywhere.
        DB::table('installments')
            ->where('plan_id', $planId)
            ->where('installment_number', 2)
            ->update(['gateway_transaction_id' => 'db_test_inst_txn']);
    }
}
