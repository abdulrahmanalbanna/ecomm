<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentsTestHelpers;
use Tests\TestCase;

/**
 * Installment settlement: sequential admin settlements, idempotent replay,
 * per-installment charge transactions, payment/plan/order advancement,
 * webhook-driven settlement, and the RBAC gate on the settle endpoint.
 */
final class InstallmentSettlementTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentsTestHelpers;

    /**
     * Fixture: a processing installment payment (100.00 / tabby 4x) with an
     * admin-created plan. Returns the payment fixture, staff token, and the
     * four installment ids in schedule order.
     *
     * @return array{fixture: array<string, mixed>, staffToken: string, installmentIds: list<int>}
     */
    private function settledPlanFixture(): array
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00', [
            'payment_method' => 'installment',
            'number_of_installments' => 4,
        ]);
        [, $staffToken] = $this->makePaymentStaff(['payments.view']);

        $paymentPublicId = (string) $fixture['payment']->public_id;

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$paymentPublicId}/installments", [
                'number_of_installments' => 4,
            ])
            ->assertStatus(201);

        $installmentIds = DB::table('installments')
            ->join('installment_plans', 'installments.plan_id', '=', 'installment_plans.id')
            ->where('installment_plans.payment_id', $fixture['payment']->id)
            ->orderBy('installments.installment_number')
            ->pluck('installments.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertCount(4, $installmentIds);

        return ['fixture' => $fixture, 'staffToken' => $staffToken, 'installmentIds' => $installmentIds];
    }

    public function test_first_settlement_books_a_charge_and_marks_payment_partially_paid(): void
    {
        ['fixture' => $fixture, 'staffToken' => $staffToken, 'installmentIds' => $ids] = $this->settledPlanFixture();
        $payment = $fixture['payment'];

        $this->assertSame('processing', (string) $payment->fresh()->status);

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/installments/{$ids[0]}/settle", [
                'gateway_transaction_id' => 'tabby_inst_1',
            ])
            ->assertStatus(200)
            ->assertJsonPath('message', 'Installment settled')
            ->assertJsonPath('data.installment_number', 1)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount', '25.00');

        $this->assertSame('partially_paid', (string) $payment->fresh()->status);

        // One charge transaction for exactly the installment amount.
        $charge = DB::table('payment_transactions')
            ->where('payment_id', $payment->id)
            ->where('transaction_type', 'charge')
            ->where('gateway_transaction_id', 'tabby_inst_1')
            ->first();

        $this->assertNotNull($charge);
        $this->assertSame('25.00', (string) $charge->amount);

        // The installment row itself carries the gateway reference.
        $this->assertSame('paid', DB::table('installments')->where('id', $ids[0])->value('status'));
        $this->assertSame('tabby_inst_1', DB::table('installments')->where('id', $ids[0])->value('gateway_transaction_id'));
        $this->assertNotNull(DB::table('installments')->where('id', $ids[0])->value('paid_at'));
    }

    public function test_settlement_out_of_sequence_is_rejected(): void
    {
        ['fixture' => $fixture, 'staffToken' => $staffToken, 'installmentIds' => $ids] = $this->settledPlanFixture();

        // Installment 3 before 1 and 2 → 422, nothing changes.
        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/installments/{$ids[2]}/settle", [
                'gateway_transaction_id' => 'tabby_inst_3',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Installment 3 cannot be settled before earlier installments are settled.']);

        $this->assertSame('pending', DB::table('installments')->where('id', $ids[2])->value('status'));
        $this->assertSame('processing', (string) $fixture['payment']->fresh()->status);
        $this->assertSame(0, DB::table('payment_transactions')->where('payment_id', $fixture['payment']->id)->count());
    }

    public function test_replaying_the_same_gateway_charge_is_idempotent(): void
    {
        ['fixture' => $fixture, 'staffToken' => $staffToken, 'installmentIds' => $ids] = $this->settledPlanFixture();

        $payload = ['gateway_transaction_id' => 'tabby_inst_1'];

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/installments/{$ids[0]}/settle", $payload)
            ->assertStatus(200);

        // Same installment, same gateway transaction id → replay, 200, no new rows.
        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/installments/{$ids[0]}/settle", $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.installment_number', 1)
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(
            1,
            DB::table('payment_transactions')
                ->where('payment_id', $fixture['payment']->id)
                ->where('gateway_transaction_id', 'tabby_inst_1')
                ->count(),
        );
    }

    public function test_settling_a_paid_installment_with_a_different_charge_is_rejected(): void
    {
        ['staffToken' => $staffToken, 'installmentIds' => $ids] = $this->settledPlanFixture();

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/installments/{$ids[0]}/settle", [
                'gateway_transaction_id' => 'tabby_inst_1',
            ])
            ->assertStatus(200);

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/installments/{$ids[0]}/settle", [
                'gateway_transaction_id' => 'tabby_inst_1_other',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Installment 1 is already settled.']);
    }

    public function test_full_plan_settlement_completes_plan_pays_payment_and_confirms_order(): void
    {
        ['fixture' => $fixture, 'staffToken' => $staffToken, 'installmentIds' => $ids] = $this->settledPlanFixture();
        $payment = $fixture['payment'];
        $orderPublicId = $fixture['order_public_id'];

        foreach ([1, 2, 3] as $index) {
            $this->authToken($staffToken)
                ->postJson("/api/v1/admin/installments/{$ids[$index - 1]}/settle", [
                    'gateway_transaction_id' => "tabby_inst_{$index}",
                ])
                ->assertStatus(200);
        }

        // Still three quarters captured.
        $this->assertSame('partially_paid', (string) $payment->fresh()->status);
        $this->assertSame('active', DB::table('installment_plans')->where('payment_id', $payment->id)->value('status'));

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/installments/{$ids[3]}/settle", [
                'gateway_transaction_id' => 'tabby_inst_4',
            ])
            ->assertStatus(200);

        // Payment fully paid, plan completed, order confirmed.
        $this->assertSame('paid', (string) $payment->fresh()->status);
        $this->assertSame('completed', DB::table('installment_plans')->where('payment_id', $payment->id)->value('status'));
        $this->assertSame('confirmed', DB::table('orders')->where('public_id', $orderPublicId)->value('status'));

        // Four charge transactions summing exactly to the payment amount.
        $sum = DB::table('payment_transactions')
            ->where('payment_id', $payment->id)
            ->where('transaction_type', 'charge')
            ->sum('amount');
        $this->assertSame('100.00', number_format((float) $sum, 2, '.', ''));

        $this->assertSame(
            ['tabby_inst_1', 'tabby_inst_2', 'tabby_inst_3', 'tabby_inst_4'],
            DB::table('installments')
                ->where('plan_id', DB::table('installment_plans')->where('payment_id', $payment->id)->value('id'))
                ->orderBy('installment_number')
                ->pluck('gateway_transaction_id')
                ->all(),
        );
    }

    public function test_installment_webhook_settles_the_matching_installment(): void
    {
        ['fixture' => $fixture] = $this->settledPlanFixture();
        $payment = $fixture['payment'];

        $response = $this->postSignedWebhook('tabby', [
            'id' => $this->uniqueEventId('inst'),
            'event' => 'installment.paid',
            'payment_id' => $fixture['session_id'],
            'installment_number' => 1,
            'transaction_id' => 'tabby_wh_inst_1',
        ])->assertStatus(200)
            ->assertJsonPath('processed', true)
            ->assertJsonPath('effect', 'installment_settled')
            ->assertJsonPath('payment_public_id', (string) $payment->public_id);

        $this->assertNotNull($response->json('payment_public_id'));

        $this->assertSame('paid', DB::table('installments')
            ->where('gateway_transaction_id', 'tabby_wh_inst_1')
            ->value('status'));
        $this->assertSame('partially_paid', (string) $payment->fresh()->status);
        $this->assertSame('25.00', (string) DB::table('payment_transactions')
            ->where('payment_id', $payment->id)
            ->where('gateway_transaction_id', 'tabby_wh_inst_1')
            ->value('amount'));
    }

    public function test_installment_webhook_without_number_falls_back_to_first_pending(): void
    {
        ['fixture' => $fixture] = $this->settledPlanFixture();
        $payment = $fixture['payment'];

        $this->postSignedWebhook('tabby', [
            'id' => $this->uniqueEventId('inst'),
            'event' => 'installment.paid',
            'payment_id' => $fixture['session_id'],
            'transaction_id' => 'tabby_wh_first',
        ])->assertStatus(200)
            ->assertJsonPath('effect', 'installment_settled');

        // Installment 1 (the first pending) was settled.
        $this->assertSame(1, (int) DB::table('installments')->where('gateway_transaction_id', 'tabby_wh_first')->value('installment_number'));
        $this->assertSame('partially_paid', (string) $payment->fresh()->status);
    }

    public function test_settle_endpoint_requires_the_payments_view_permission(): void
    {
        ['installmentIds' => $ids] = $this->settledPlanFixture();

        // No token at all → 401 (headers from the fixture must be cleared).
        $this->flushHeaders();
        $this->postJson("/api/v1/admin/installments/{$ids[0]}/settle", [
            'gateway_transaction_id' => 'tabby_inst_x',
        ])->assertStatus(401);

        // Authenticated user WITHOUT payments.view (customer role) → 403.
        $customer = $this->makeCustomer();
        $this->flushHeaders();
        $this->authToken($this->issueToken($customer))
            ->postJson("/api/v1/admin/installments/{$ids[0]}/settle", [
                'gateway_transaction_id' => 'tabby_inst_x',
            ])
            ->assertStatus(403);

        // Validation: gateway_transaction_id is required.
        $this->flushHeaders();
        [, $staffToken] = $this->makePaymentStaff(['payments.view']);
        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/installments/{$ids[0]}/settle", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gateway_transaction_id');
    }

    public function test_unknown_installment_id_is_rejected(): void
    {
        [, $staffToken] = $this->makePaymentStaff(['payments.view']);

        // InstallmentException maps to the default 422 in MapsPaymentExceptions.
        $this->authToken($staffToken)
            ->postJson('/api/v1/admin/installments/99999999/settle', [
                'gateway_transaction_id' => 'tabby_inst_ghost',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Installment not found for this payment.']);
    }
}
