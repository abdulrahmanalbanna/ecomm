<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentsTestHelpers;
use Tests\TestCase;

/**
 * Installment plans: creation through the admin API, gateway capability
 * guards, exact decimal split (no float drift), replay, and the DB CHECK
 * that only 3/4/6 counts exist.
 */
final class InstallmentPlanTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentsTestHelpers;

    public function test_admin_creates_tabby_4x_plan_with_exact_split(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00', [
            'payment_method' => 'installment',
            'number_of_installments' => 4,
        ]);
        [, $staffToken] = $this->makePaymentStaff(['payments.view']);

        $paymentPublicId = (string) $fixture['payment']->public_id;

        $this->fakeGatewayHttp();

        $response = $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$paymentPublicId}/installments", [
                'number_of_installments' => 4,
            ])
            ->assertStatus(201)
            ->assertJsonPath('message', 'Installment plan created')
            ->assertJsonPath('data.number_of_installments', 4)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.frequency', 'monthly');

        // 100.00 / 4 = 25.00 exactly; plan-level amount is the recurring one.
        $this->assertSame('25.00', $response->json('data.installment_amount'));

        $installments = $response->json('data.installments');
        $this->assertCount(4, $installments);
        $this->assertSame([1, 2, 3, 4], array_column($installments, 'installment_number'));
        $this->assertSame(['25.00', '25.00', '25.00', '25.00'], array_column($installments, 'amount'));
        $this->assertSame(['pending', 'pending', 'pending', 'pending'], array_column($installments, 'status'));

        // Due dates step monthly from the first payment date.
        $dueDates = array_column($installments, 'due_date');
        $this->assertSame($response->json('data.first_payment_date'), $dueDates[0]);
        $first = new \DateTimeImmutable((string) $dueDates[0]);
        $this->assertSame($first->modify('+1 month')->format('Y-m-d'), $dueDates[1]);
        $this->assertSame($first->modify('+3 month')->format('Y-m-d'), $dueDates[3]);

        // Customer-safe resource hides the gateway transaction id.
        $this->assertArrayNotHasKey('gateway_transaction_id', $installments[0]);
    }

    public function test_uneven_split_keeps_exact_decimal_total(): void
    {
        [, $staffToken] = $this->makePaymentStaff(['payments.view']);

        $this->fakeGatewayHttp();

        // 100.00 across 3 does not divide evenly — the remainder goes to the
        // first installment so the schedule still sums to the exact total.
        $tamara = $this->makeProcessingPayment('tamara', '100.00', [
            'payment_method' => 'installment',
            'number_of_installments' => 3,
        ]);
        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$tamara['payment']->public_id}/installments", [
                'number_of_installments' => 3,
            ])
            ->assertStatus(201);

        $amounts = DB::table('installments')
            ->join('installment_plans', 'installment_plans.id', '=', 'installments.plan_id')
            ->where('installment_plans.payment_id', $tamara['payment']->id)
            ->orderBy('installments.installment_number')
            ->pluck('installments.amount')
            ->map(static fn ($a): string => (string) $a)
            ->all();

        $this->assertSame(['33.34', '33.33', '33.33'], $amounts);

        $total = '0.00';
        foreach ($amounts as $amount) {
            $total = bcadd($total, $amount, 2);
        }
        $this->assertSame('100.00', $total);
    }

    public function test_plan_requires_installment_payment_method(): void
    {
        $fixture = $this->makeProcessingPayment('tabby');
        [, $staffToken] = $this->makePaymentStaff(['payments.view']);

        $this->fakeGatewayHttp();

        // The payment was created with payment_method = full.
        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$fixture['payment']->public_id}/installments", [
                'number_of_installments' => 4,
            ])
            ->assertStatus(422);
    }

    public function test_gateway_capability_guard_rejects_unsupported_counts(): void
    {
        $tabby = $this->makeProcessingPayment('tabby', '100.00', [
            'payment_method' => 'installment',
            'number_of_installments' => 4,
        ]);
        [, $staffToken] = $this->makePaymentStaff(['payments.view']);

        $this->fakeGatewayHttp();

        // Tabby supports 4 only.
        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$tabby['payment']->public_id}/installments", [
                'number_of_installments' => 6,
            ])
            ->assertStatus(422);
    }

    public function test_installment_intent_then_plan_is_visible_on_the_customer_surface(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('240.00', 5);
        $orderPublicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response($this->tabbyPending('tabby_sess_plan'))]);

        $paymentPublicId = (string) $this->createIntent($user, $token, $orderPublicId, [
            'payment_method' => 'installment',
            'number_of_installments' => 4,
        ])->assertStatus(201)->assertJsonPath('data.payment_method', 'installment')->json('data.public_id');

        // Before the plan is materialized, the customer sees no schedule
        // (the nested resource resolves to null when the relation is empty).
        $this->assertNull($this->authToken($token)->getJson("/api/v1/customer/payments/{$paymentPublicId}")
            ->assertStatus(200)
            ->json('data.installment_plan'));

        [, $staffToken] = $this->makePaymentStaff(['payments.view']);

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$paymentPublicId}/installments", ['number_of_installments' => 4])
            ->assertStatus(201);

        // Customer response now includes the plan and schedule (no internal ids).
        $plan = $this->authToken($token)->getJson("/api/v1/customer/payments/{$paymentPublicId}")
            ->assertStatus(200)
            ->json('data.installment_plan');

        $this->assertSame(4, (int) $plan['number_of_installments']);
        $this->assertSame('60.00', $plan['installment_amount']);
        $this->assertCount(4, $plan['installments']);
        $this->assertArrayNotHasKey('id', $plan);
    }

    public function test_duplicate_plan_with_same_count_is_a_replay_and_different_count_conflicts(): void
    {
        $fixture = $this->makeProcessingPayment('tamara', '180.00', [
            'payment_method' => 'installment',
            'number_of_installments' => 3,
        ]);
        [, $staffToken] = $this->makePaymentStaff(['payments.view']);

        $this->fakeGatewayHttp();

        $paymentPublicId = (string) $fixture['payment']->public_id;

        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$paymentPublicId}/installments", ['number_of_installments' => 3])
            ->assertStatus(201);

        // Same count → replay (still one plan).
        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$paymentPublicId}/installments", ['number_of_installments' => 3])
            ->assertStatus(201);

        $this->assertSame(
            1,
            (int) DB::table('installment_plans')->where('payment_id', $fixture['payment']->id)->count(),
        );

        // Different count → conflict (installment domain exception → 422).
        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$paymentPublicId}/installments", ['number_of_installments' => 6])
            ->assertStatus(422);
    }

    public function test_database_rejects_invalid_installment_counts_and_numbers(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00', [
            'payment_method' => 'installment',
            'number_of_installments' => 4,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('installment_plans')->insert([
            'payment_id' => (int) $fixture['payment']->id,
            'number_of_installments' => 5,
            'installment_amount' => '20.00',
            'first_payment_date' => now()->toDateString(),
            'frequency' => 'monthly',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_installment_number_beyond_plan_count_is_rejected_by_the_database(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00', [
            'payment_method' => 'installment',
            'number_of_installments' => 4,
        ]);
        [, $staffToken] = $this->makePaymentStaff(['payments.view']);

        $this->fakeGatewayHttp();

        // Materialize the plan through the admin API first (the intent flow
        // never creates one), so the trigger — not an FK — must reject #9.
        $this->authToken($staffToken)
            ->postJson("/api/v1/admin/payments/{$fixture['payment']->public_id}/installments", [
                'number_of_installments' => 4,
            ])
            ->assertStatus(201);

        $planId = (int) DB::table('installment_plans')->where('payment_id', $fixture['payment']->id)->value('id');
        $this->assertGreaterThan(0, $planId);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('installments')->insert([
            'plan_id' => $planId,
            'installment_number' => 9,
            'amount' => '25.00',
            'due_date' => now()->addMonths(9)->toDateString(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}