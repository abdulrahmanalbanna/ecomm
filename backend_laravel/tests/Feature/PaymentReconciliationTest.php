<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentsTestHelpers;
use Tests\TestCase;

/**
 * Reconciliation: admin-triggered gateway audit that reports discrepancies
 * and applies CONSERVATIVE corrections (settling a missing charge, walking
 * the state machine along a legal path) — never forcing illegal transitions.
 */
final class PaymentReconciliationTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentsTestHelpers;

    public function test_reconcile_settles_a_missing_charge_and_confirms_the_order(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00');
        $payment = $fixture['payment'];
        [, $staffToken] = $this->makePaymentStaff(['payments.reconcile']);

        // Gateway says PAID with a real transaction id, but the webhook was
        // never delivered — the local ledger has no charge at all.
        $this->fakeGatewayHttp([
            'api.tabby.ai/*' => Http::response($this->tabbySuccess('tabby_txn_recon_1')),
        ]);

        $response = $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$payment->public_id}/reconcile")
            ->assertStatus(200)
            ->assertJsonPath('data.corrections', ['settled_missing_charge', 'status_corrected:processing->paid'])
            ->assertJsonPath('data.report.local_status', 'paid')
            ->assertJsonPath('data.report.expected_status', 'paid')
            ->assertJsonPath('data.report.settled_charges', '100.00')
            ->assertJsonPath('data.report.gateway_result_status', 'success')
            ->assertJsonPath('data.report.discrepancies', []);

        $this->assertSame((int) $payment->id, (int) $response->json('data.report.payment_id'));

        // Ledger + payment + order all corrected.
        $this->assertSame('paid', (string) $payment->fresh()->status);
        $this->assertSame(
            '100.00',
            (string) DB::table('payment_transactions')
                ->where('payment_id', $payment->id)
                ->where('transaction_type', 'charge')
                ->where('gateway_transaction_id', 'tabby_txn_recon_1')
                ->value('amount'),
        );
        $this->assertSame('confirmed', DB::table('orders')->where('public_id', $fixture['order_public_id'])->value('status'));
    }

    public function test_reconcile_only_settles_the_missing_portion_of_a_partial_charge(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00');
        $payment = $fixture['payment'];
        [, $staffToken] = $this->makePaymentStaff(['payments.reconcile']);

        // A 50.00 charge already exists (e.g. a lost partial capture event).
        DB::table('payment_transactions')->insert([
            'payment_id' => $payment->id,
            'transaction_type' => 'charge',
            'gateway_transaction_id' => 'tabby_txn_partial_50',
            'amount' => '50.00',
            'currency' => $payment->currency,
            'settled_at' => now(),
            'created_at' => now(),
        ]);

        $this->fakeGatewayHttp([
            'api.tabby.ai/*' => Http::response($this->tabbySuccess('tabby_txn_recon_topup')),
        ]);

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$payment->public_id}/reconcile")
            ->assertStatus(200)
            ->assertJsonPath('data.corrections', ['settled_missing_charge', 'status_corrected:processing->paid'])
            ->assertJsonPath('data.report.settled_charges', '100.00');

        // The correction booked exactly the 50.00 shortfall, not the full amount.
        $this->assertSame(
            '50.00',
            (string) DB::table('payment_transactions')
                ->where('payment_id', $payment->id)
                ->where('gateway_transaction_id', 'tabby_txn_recon_topup')
                ->value('amount'),
        );
        $this->assertSame('paid', (string) $payment->fresh()->status);
    }

    public function test_a_missing_charge_without_a_gateway_reference_is_reported_not_guessed(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00');
        $payment = $fixture['payment'];
        [, $staffToken] = $this->makePaymentStaff(['payments.reconcile']);

        // PAID but no payment id in the body → nothing to settle against.
        $this->fakeGatewayHttp([
            'api.tabby.ai/*' => Http::response(['status' => 'PAID']),
        ]);

        $response = $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$payment->public_id}/reconcile")
            ->assertStatus(200)
            ->assertJsonPath('data.report.settled_charges', '0.00');

        // The missing charge is REPORTED, never settled with a synthesized id.
        $this->assertSame(
            'missing_charge_unsettled_no_gateway_reference',
            $response->json('data.corrections')[0],
        );
        $this->assertSame(
            0,
            DB::table('payment_transactions')->where('payment_id', $payment->id)->count(),
        );

        // The gateway evidence still supports a conservative status hop
        // (processing → partially_paid) — no charge was invented for it.
        $this->assertSame('partially_paid', (string) $payment->fresh()->status);
    }

    public function test_terminal_local_state_that_contradicts_the_gateway_is_reported_uncorrectable(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00');
        $payment = $fixture['payment'];
        [, $staffToken] = $this->makePaymentStaff(['payments.reconcile']);

        // Local payment is terminal (cancelled via webhook)…
        $this->postSignedWebhook('tabby', [
            'id' => $this->uniqueEventId('cancel'),
            'event' => 'payment.cancelled',
            'payment_id' => $fixture['session_id'],
        ])->assertStatus(200);
        $this->assertSame('cancelled', (string) $payment->fresh()->status);

        // …but the gateway now reports it PAID. cancelled → paid is illegal:
        // reconcile must report, never force.
        $this->fakeGatewayHttp([
            'api.tabby.ai/*' => Http::response($this->tabbySuccess('tabby_txn_ghost')),
        ]);

        $response = $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$payment->public_id}/reconcile")
            ->assertStatus(200)
            ->assertJsonPath('data.report.local_status', 'cancelled')
            ->assertJsonPath('data.report.expected_status', 'paid');

        // The charge evidence is appended to the ledger, but the terminal
        // status is NEVER forced: no status_corrected entry.
        $this->assertSame(['settled_missing_charge'], $response->json('data.corrections'));
        $this->assertContains('status_mismatch', $response->json('data.report.discrepancies'));
        $this->assertContains('uncorrectable_status_mismatch', $response->json('data.report.discrepancies'));

        $this->assertSame('cancelled', (string) $payment->fresh()->status);
    }

    public function test_reconcile_of_a_consistent_paid_payment_is_a_no_op(): void
    {
        $fixture = $this->makePaidPayment('tabby', '100.00');
        $payment = $fixture['payment'];
        [, $staffToken] = $this->makePaymentStaff(['payments.reconcile']);

        // Gateway agrees: PAID with the same transaction the webhook settled.
        $this->fakeGatewayHttp([
            'api.tabby.ai/*' => Http::response($this->tabbySuccess('tabby_txn_' . substr(md5((string) $fixture['order_public_id']), 0, 8))),
        ]);

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$payment->public_id}/reconcile")
            ->assertStatus(200)
            ->assertJsonPath('data.corrections', [])
            ->assertJsonPath('data.report.local_status', 'paid')
            ->assertJsonPath('data.report.expected_status', 'paid')
            ->assertJsonPath('data.report.settled_charges', '100.00')
            ->assertJsonPath('data.report.discrepancies', []);
    }

    public function test_pending_gateway_snapshot_produces_no_corrections(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00');
        $payment = $fixture['payment'];
        [, $staffToken] = $this->makePaymentStaff(['payments.reconcile']);

        $this->fakeGatewayHttp([
            'api.tabby.ai/*' => Http::response($this->tabbyPending($fixture['session_id'])),
        ]);

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$payment->public_id}/reconcile")
            ->assertStatus(200)
            ->assertJsonPath('data.corrections', [])
            ->assertJsonPath('data.report.local_status', 'processing')
            ->assertJsonPath('data.report.gateway_result_status', 'pending')
            ->assertJsonPath('data.report.expected_status', null);

        $this->assertSame('processing', (string) $payment->fresh()->status);
    }

    public function test_reconcile_requires_the_payments_reconcile_permission(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00');
        $payment = $fixture['payment'];

        // Customer (no payments.reconcile) → 403.
        $this->flushHeaders();
        $customer = $this->makeCustomer();
        $this->authToken($this->issueToken($customer))
            ->postJson("/api/v1/admin/payments/{$payment->public_id}/reconcile")
            ->assertStatus(403);

        // Unauthenticated → 401.
        $this->flushHeaders();
        $this->postJson("/api/v1/admin/payments/{$payment->public_id}/reconcile")
            ->assertStatus(401);
    }

    public function test_reconcile_of_an_unknown_payment_returns_404(): void
    {
        [, $staffToken] = $this->makePaymentStaff(['payments.reconcile']);

        $this->authToken($staffToken)
            ->postJson('/api/v1/admin/payments/' . \Illuminate\Support\Str::uuid() . '/reconcile')
            ->assertStatus(404);
    }
}
