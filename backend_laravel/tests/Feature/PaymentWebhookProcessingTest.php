<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentsTestHelpers;
use Tests\TestCase;

/**
 * Webhook processing: persist-before-process, deduplication via
 * gateway_event_id UNIQUE, payment.paid → paid + order confirmed, failure
 * events, and replayable 202s for unmatched references.
 */
final class PaymentWebhookProcessingTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentsTestHelpers;

    public function test_payment_paid_webhook_settles_payment_and_confirms_order(): void
    {
        $fixture = $this->makeProcessingPayment('tabby');

        $paymentPublicId = (string) $fixture['payment']->public_id;
        $eventId = $this->uniqueEventId();

        $this->postSignedWebhook('tabby', [
            'id' => $eventId,
            'event' => 'payment.paid',
            'payment_id' => $fixture['session_id'],
        ])->assertStatus(200)
            ->assertJsonPath('processed', true)
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('effect', 'payment_paid')
            ->assertJsonPath('payment_public_id', $paymentPublicId);

        $payment = $fixture['payment']->fresh();
        $this->assertSame('paid', (string) $payment->status);

        // Settled charge on the ledger.
        $txn = DB::table('payment_transactions')->where('payment_id', $payment->id)->first();
        $this->assertNotNull($txn);
        $this->assertSame('charge', (string) $txn->transaction_type);
        $this->assertSame((string) $payment->amount, (string) $txn->amount);
        $this->assertNotNull($txn->settled_at);

        // Attempt marked success.
        $this->assertSame('success', (string) DB::table('payment_attempts')->where('id', $txn->attempt_id)->value('status'));

        // Order confirmed via the Orders state machine.
        $this->assertSame('confirmed', (string) DB::table('orders')->where('id', $payment->order_id)->value('status'));

        // Event flagged processed.
        $event = $this->webhookEventRow($eventId);
        $this->assertNotNull($event);
        $this->assertTrue((bool) $event['processed']);
        $this->assertNotNull($event['processed_at']);
    }

    public function test_duplicate_event_delivery_is_ignored_without_reprocessing(): void
    {
        $fixture = $this->makeProcessingPayment('tabby');

        $eventId = $this->uniqueEventId();

        $payload = [
            'id' => $eventId,
            'event' => 'payment.paid',
            'payment_id' => $fixture['session_id'],
        ];

        $this->postSignedWebhook('tabby', $payload)->assertStatus(200)->assertJsonPath('duplicate', false);

        // Re-delivery of the SAME event id.
        $this->postSignedWebhook('tabby', $payload)
            ->assertStatus(200)
            ->assertJsonPath('message', 'Event already received')
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('effect', 'ignored_duplicate');

        // Exactly one webhook event row and one settled charge.
        $this->assertSame(1, (int) DB::table('payment_webhook_events')->where('gateway_event_id', $eventId)->count());
        $this->assertSame(1, (int) DB::table('payment_transactions')->where('payment_id', $fixture['payment']->id)->count());
    }

    public function test_payment_failed_webhook_marks_payment_failed(): void
    {
        $fixture = $this->makeProcessingPayment('tabby');

        $this->postSignedWebhook('tabby', [
            'id' => $this->uniqueEventId(),
            'event' => 'payment.failed',
            'payment_id' => $fixture['session_id'],
        ])->assertStatus(200)->assertJsonPath('effect', 'payment_failed');

        $this->assertSame('failed', (string) $fixture['payment']->fresh()->status);
        // The order is NOT auto-failed for 'failed' (only cancellation fails it).
        $this->assertSame('payment_pending', (string) DB::table('orders')->where('id', $fixture['payment']->order_id)->value('status'));
    }

    public function test_checkout_cancelled_webhook_cancels_payment_and_fails_order(): void
    {
        $fixture = $this->makeProcessingPayment('tabby');

        $this->postSignedWebhook('tabby', [
            'id' => $this->uniqueEventId(),
            'event' => 'checkout.cancelled',
            'checkout_id' => $fixture['session_id'],
        ])->assertStatus(200)->assertJsonPath('effect', 'payment_cancelled');

        $this->assertSame('cancelled', (string) $fixture['payment']->fresh()->status);
        $this->assertSame('failed', (string) DB::table('orders')->where('id', $fixture['payment']->order_id)->value('status'));
    }

    public function test_unmatched_reference_is_persisted_for_replay_and_returns_202(): void
    {
        $eventId = $this->uniqueEventId();

        $this->postSignedWebhook('tabby', [
            'id' => $eventId,
            'event' => 'payment.paid',
            'payment_id' => 'tabby_sess_unknown',
        ])->assertStatus(202)->assertJsonPath('processed', false);

        // The raw event is preserved (persist-before-process) for later replay.
        $event = $this->webhookEventRow($eventId);
        $this->assertNotNull($event);
        $this->assertFalse((bool) $event['processed']);
        $this->assertNotNull($event['error_message']);
        $payload = json_decode((string) $event['payload'], true);
        $this->assertSame('tabby_sess_unknown', $payload['payment_id']);
    }

    public function test_tamara_paid_webhook_matches_by_checkout_id(): void
    {
        $fixture = $this->makeProcessingPayment('tamara');

        $this->postSignedWebhook('tamara', [
            'id' => $this->uniqueEventId(),
            'event' => 'payment.captured',
            'checkoutId' => $fixture['session_id'],
            'paymentId' => 'tamara_pay_42',
        ])->assertStatus(200)->assertJsonPath('effect', 'payment_paid');

        $this->assertSame('paid', (string) $fixture['payment']->fresh()->status);
        $this->assertSame('confirmed', (string) DB::table('orders')->where('id', $fixture['payment']->order_id)->value('status'));
    }

    public function test_webhook_matching_by_payment_public_id_uuid(): void
    {
        $fixture = $this->makeProcessingPayment('tabby');

        // Tabby echoes our reference (payment public_id) back in reference_code.
        $this->postSignedWebhook('tabby', [
            'id' => $this->uniqueEventId(),
            'event' => 'payment.paid',
            'reference_code' => (string) $fixture['payment']->public_id,
        ])->assertStatus(200)->assertJsonPath('effect', 'payment_paid');

        $this->assertSame('paid', (string) $fixture['payment']->fresh()->status);
    }

    public function test_illegal_transition_from_webhook_is_persisted_for_replay(): void
    {
        // A paid payment receiving a second (different-id) paid event would
        // attempt paid → paid; the state machine treats it as a no-op, so use
        // a cancelled payment receiving a paid event → illegal per trigger.
        $fixture = $this->makeProcessingPayment('tabby');

        $this->postSignedWebhook('tabby', [
            'id' => $this->uniqueEventId(),
            'event' => 'checkout.cancelled',
            'payment_id' => $fixture['session_id'],
        ])->assertStatus(200);

        $illegal = $this->postSignedWebhook('tabby', [
            'id' => $this->uniqueEventId(),
            'event' => 'payment.paid',
            'payment_id' => $fixture['session_id'],
        ]);

        $illegal->assertStatus(202)->assertJsonPath('processed', false);

        // Payment stays cancelled — the trigger refused the write.
        $this->assertSame('cancelled', (string) $fixture['payment']->fresh()->status);
    }
}
