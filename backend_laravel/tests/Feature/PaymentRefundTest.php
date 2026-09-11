<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentsTestHelpers;
use Tests\TestCase;

/**
 * Refunds (admin): partial and full, synchronous gateway settlement,
 * reservation release on gateway rejection, and the refundable-state guard.
 */
final class PaymentRefundTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentsTestHelpers;

    public function test_partial_refund_settles_and_marks_payment_partially_refunded(): void
    {
        $fixture = $this->makePaidPayment('tabby');
        [$staff, $staffToken] = $this->makePaymentStaff(['payments.view', 'payments.refund']);

        $paymentPublicId = (string) $fixture['payment']->public_id;

        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response($this->tabbyRefundAccepted('tabby_ref_partial'))]);

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$paymentPublicId}/refunds", [
                'amount' => '40.00',
                'reason' => 'Damaged item',
            ])
            ->assertStatus(201)
            ->assertJsonPath('message', 'Refund initiated')
            ->assertJsonPath('data.amount', '40.00')
            ->assertJsonPath('data.status', 'processed')
            ->assertJsonPath('data.gateway_refund_id', 'tabby_ref_partial');

        $payment = $fixture['payment']->fresh();
        $this->assertSame('partially_refunded', (string) $payment->status);

        // Refund transaction settled on the ledger.
        $txn = DB::table('payment_transactions')
            ->where('payment_id', $payment->id)
            ->where('transaction_type', 'refund')
            ->first();
        $this->assertNotNull($txn);
        $this->assertSame('40.00', (string) $txn->amount);
        $this->assertSame('tabby_ref_partial', (string) $txn->gateway_transaction_id);

        // Refund references the original settled charge (composite FK) and
        // records the initiating staff member.
        $chargeId = (int) DB::table('payment_transactions')
            ->where('payment_id', $payment->id)
            ->where('transaction_type', 'charge')
            ->value('id');
        $refundRow = DB::table('refunds')->where('payment_id', $payment->id)->first();
        $this->assertSame($chargeId, (int) $refundRow->transaction_id);
        $this->assertSame((int) $staff->id, (int) $refundRow->initiated_by);
    }

    public function test_full_refund_marks_payment_refunded(): void
    {
        $fixture = $this->makePaidPayment('tabby');
        [, $staffToken] = $this->makePaymentStaff(['payments.view', 'payments.refund']);

        $paymentPublicId = (string) $fixture['payment']->public_id;
        $amount = (string) $fixture['payment']->amount;

        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response($this->tabbyRefundAccepted('tabby_ref_full'))]);

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$paymentPublicId}/refunds", ['amount' => $amount])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'processed');

        $this->assertSame('refunded', (string) $fixture['payment']->fresh()->status);
    }

    /**
     * The refund guard is one-in-flight: once a partial refund settles, the
     * payment sits in partially_refunded, which is not a refundable state, so
     * a second API refund is refused. The remaining balance is then closed out
     * by the gateway's own refund event (the ledger aggregate reaches the full
     * amount and the trigger permits partially_refunded → refunded).
     */
    public function test_partial_refund_then_webhook_completion_reaches_refunded(): void
    {
        $fixture = $this->makePaidPayment('tabby', '100.00');
        [, $staffToken] = $this->makePaymentStaff(['payments.view', 'payments.refund']);

        $paymentPublicId = (string) $fixture['payment']->public_id;
        $payment = $fixture['payment'];

        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response($this->tabbyRefundAccepted('tabby_ref_p1'))]);

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$paymentPublicId}/refunds", ['amount' => '60.00'])
            ->assertStatus(201);

        $this->assertSame('partially_refunded', (string) $payment->fresh()->status);

        // A second refund through the API is refused: partially_refunded is
        // not a refundable state (one in-flight refund at a time).
        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$paymentPublicId}/refunds", ['amount' => '40.00'])
            ->assertStatus(422);

        // The remaining 40.00 is settled by the gateway's refund event.
        $chargeId = (int) DB::table('payment_transactions')
            ->where('payment_id', $payment->id)
            ->where('transaction_type', 'charge')
            ->value('id');

        DB::table('refunds')->insert([
            'payment_id' => (int) $payment->id,
            'transaction_id' => $chargeId,
            'amount' => '40.00',
            'reason' => 'Balance',
            'gateway_refund_id' => 'tabby_ref_p2',
            'status' => 'processing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postSignedWebhook('tabby', [
            'id' => $this->uniqueEventId('ref_close'),
            'event' => 'refund.processed',
            'payment_id' => $fixture['session_id'],
            'refund_id' => 'tabby_ref_p2',
        ])->assertStatus(200)->assertJsonPath('effect', 'refund_processed');

        $this->assertSame('refunded', (string) $payment->fresh()->status);

        // The ledger records both refunds and their aggregate equals the charge.
        $this->assertSame(
            '100.00',
            (string) DB::table('payment_transactions')
                ->where('payment_id', $payment->id)
                ->where('transaction_type', 'refund')
                ->sum('amount'),
        );
    }

    public function test_gateway_rejected_refund_releases_reservation_and_keeps_payment_refundable(): void
    {
        $fixture = $this->makePaidPayment('tabby');
        [, $staffToken] = $this->makePaymentStaff(['payments.view', 'payments.refund']);

        $paymentPublicId = (string) $fixture['payment']->public_id;

        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response(['code' => 'refund_not_allowed', 'message' => 'no'], 400)]);

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$paymentPublicId}/refunds", ['amount' => '50.00'])
            ->assertStatus(502);

        $refund = DB::table('refunds')->where('payment_id', $fixture['payment']->id)->first();
        $this->assertSame('failed', (string) $refund->status);

        // Payment stays paid (refundable) — the failed reservation does not
        // count toward the balance cap.
        $this->assertSame('paid', (string) $fixture['payment']->fresh()->status);

        // A retry of the refund now succeeds (fresh stubs — the 400 stub
        // must not shadow this one).
        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response($this->tabbyRefundAccepted('tabby_ref_retry'))]);

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$paymentPublicId}/refunds", ['amount' => '50.00'])
            ->assertStatus(201);
    }

    public function test_refund_requires_a_settled_payment(): void
    {
        $fixture = $this->makeProcessingPayment('tabby');
        [, $staffToken] = $this->makePaymentStaff(['payments.view', 'payments.refund']);

        $this->fakeGatewayHttp();

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$fixture['payment']->public_id}/refunds", ['amount' => '10.00'])
            ->assertStatus(422);
    }

    public function test_refund_amount_validation_rejects_non_decimal_and_negative(): void
    {
        $fixture = $this->makePaidPayment('tabby');
        [, $staffToken] = $this->makePaymentStaff(['payments.view', 'payments.refund']);

        $this->fakeGatewayHttp();

        $paymentPublicId = (string) $fixture['payment']->public_id;

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$paymentPublicId}/refunds", ['amount' => '-5.00'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$paymentPublicId}/refunds", ['amount' => '10.000'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_async_refund_is_completed_by_its_webhook(): void
    {
        $fixture = $this->makePaidPayment('tabby');

        $payment = $fixture['payment'];

        // Simulate an async submission: the refund row is reserved and the
        // payment is in refund_pending, awaiting the gateway's decision.
        $chargeId = (int) DB::table('payment_transactions')
            ->where('payment_id', $payment->id)
            ->where('transaction_type', 'charge')
            ->value('id');

        DB::table('refunds')->insert([
            'payment_id' => (int) $payment->id,
            'transaction_id' => $chargeId,
            'amount' => '40.00',
            'reason' => 'Async refund',
            'gateway_refund_id' => 'tabby_ref_async',
            'status' => 'processing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payments')->where('id', $payment->id)->update(['status' => 'refund_pending']);

        $this->postSignedWebhook('tabby', [
            'id' => $this->uniqueEventId('ref_evt'),
            'event' => 'refund.processed',
            'payment_id' => $fixture['session_id'],
            'refund_id' => 'tabby_ref_async',
        ])->assertStatus(200)->assertJsonPath('effect', 'refund_processed');

        $this->assertSame('partially_refunded', (string) $payment->fresh()->status);
        $this->assertSame('processed', (string) DB::table('refunds')->where('gateway_refund_id', 'tabby_ref_async')->value('status'));

        // The refund settlement is recorded once on the ledger.
        $this->assertSame(
            '40.00',
            (string) DB::table('payment_transactions')
                ->where('payment_id', $payment->id)
                ->where('transaction_type', 'refund')
                ->sum('amount'),
        );
    }

    public function test_failed_refund_webhook_marks_refund_failed_without_refunding(): void
    {
        $fixture = $this->makePaidPayment('tabby');

        $payment = $fixture['payment'];

        $chargeId = (int) DB::table('payment_transactions')
            ->where('payment_id', $payment->id)
            ->where('transaction_type', 'charge')
            ->value('id');

        DB::table('refunds')->insert([
            'payment_id' => (int) $payment->id,
            'transaction_id' => $chargeId,
            'amount' => '25.00',
            'status' => 'processing',
            'gateway_refund_id' => 'tabby_ref_doomed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payments')->where('id', $payment->id)->update(['status' => 'refund_pending']);

        $this->postSignedWebhook('tabby', [
            'id' => $this->uniqueEventId('ref_evt'),
            'event' => 'refund.failed',
            'payment_id' => $fixture['session_id'],
            'refund_id' => 'tabby_ref_doomed',
        ])->assertStatus(200)->assertJsonPath('effect', 'refund_failed');

        $this->assertSame('failed', (string) DB::table('refunds')->where('gateway_refund_id', 'tabby_ref_doomed')->value('status'));

        // No refund transaction was booked; the failed row no longer counts
        // toward the balance, so the amount is refundable again.
        $this->assertSame(
            0,
            (int) DB::table('payment_transactions')->where('payment_id', $payment->id)->where('transaction_type', 'refund')->count(),
        );
    }
}
