<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentsTestHelpers;
use Tests\TestCase;

/**
 * Payment listing surfaces: customer (own payments only, paginated,
 * filtered) and admin (all payments, gateway/method filters, ledger
 * relations in the detail view).
 */
final class PaymentListingTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentsTestHelpers;

    public function test_customer_index_only_contains_their_own_payments(): void
    {
        $mine = $this->makeProcessingPayment('tabby', '100.00');
        $theirs = $this->makeProcessingPayment('tabby', '70.00');

        $response = $this->authToken($mine['token'])
            ->getJson('/api/v1/customer/payments')
            ->assertStatus(200)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15);

        $publicIds = array_column($response->json('data'), 'public_id');

        $this->assertContains((string) $mine['payment']->public_id, $publicIds);
        $this->assertNotContains((string) $theirs['payment']->public_id, $publicIds);
        $this->assertSame((int) $response->json('meta.total'), count($publicIds));
    }

    public function test_customer_index_paginates_and_filters_by_status(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);

        // Two orders → two payments for the same customer.
        $first = $this->paymentForUser($user, $token, 'tabby', '100.00');
        $second = $this->paymentForUser($user, $token, 'tamara', '60.00');

        // Pay the second one so statuses differ.
        $this->postSignedWebhook('tamara', [
            'id' => $this->uniqueEventId('paid'),
            'event' => 'payment.paid',
            'payment_id' => $second['session_id'],
        ])->assertStatus(200);

        $all = $this->authToken($token)->getJson('/api/v1/customer/payments')->assertStatus(200);
        $this->assertSame(2, (int) $all->json('meta.total'));

        $paidOnly = $this->authToken($token)
            ->getJson('/api/v1/customer/payments?status=paid')
            ->assertStatus(200);
        $this->assertSame(1, (int) $paidOnly->json('meta.total'));
        $this->assertSame(
            (string) $second['payment']->public_id,
            $paidOnly->json('data.0.public_id'),
        );

        // Pagination window.
        $page = $this->authToken($token)
            ->getJson('/api/v1/customer/payments?per_page=1')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data');

        // Newest first (latest('id')).
        $this->assertSame((string) $second['payment']->public_id, $page->json('data.0.public_id'));
    }

    public function test_customer_index_rejects_unknown_status_filter_gracefully(): void
    {
        $fixture = $this->makeProcessingPayment('tabby', '100.00');

        // Unknown status values are ignored (not an error) — the list is
        // simply unfiltered.
        $response = $this->authToken($fixture['token'])
            ->getJson('/api/v1/customer/payments?status=not_a_real_status')
            ->assertStatus(200);

        $this->assertSame(1, (int) $response->json('meta.total'));
    }

    public function test_admin_index_filters_by_gateway_and_payment_method(): void
    {
        $tabby = $this->makeProcessingPayment('tabby', '100.00');
        $tamara = $this->makeProcessingPayment('tamara', '80.00');
        [, $staffToken] = $this->makePaymentStaff(['payments.view']);

        $byGateway = $this->authToken($staffToken)
            ->getJson('/api/v1/admin/payments?gateway=tamara')
            ->assertStatus(200);

        $codes = array_unique(array_column(array_column($byGateway->json('data'), 'gateway'), 'code'));
        $this->assertSame(['tamara'], $codes);

        $ids = array_column($byGateway->json('data'), 'public_id');
        $this->assertContains((string) $tamara['payment']->public_id, $ids);
        $this->assertNotContains((string) $tabby['payment']->public_id, $ids);

        // payment_method filter.
        $byMethod = $this->authToken($staffToken)
            ->getJson('/api/v1/admin/payments?payment_method=installment')
            ->assertStatus(200);
        $methods = array_unique(array_column($byMethod->json('data'), 'payment_method'));
        $this->assertSame([], array_values(array_diff($methods, ['installment'])));
    }

    public function test_admin_detail_exposes_the_full_ledger_and_customer_detail_does_not(): void
    {
        $fixture = $this->makePaidPayment('tabby', '100.00');
        $payment = $fixture['payment'];
        [, $staffToken] = $this->makePaymentStaff(['payments.view']);

        $admin = $this->authToken($staffToken)
            ->getJson("/api/v1/admin/payments/{$payment->public_id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.gateway_payment_id', $fixture['session_id'])
            ->assertJsonCount(1, 'data.attempts')
            ->assertJsonCount(1, 'data.transactions');

        // Admin sees internal ledger fields; the customer-safe resource does
        // not expose gateway_payment_id or idempotency_key.
        $this->assertArrayHasKey('idempotency_key', $admin->json('data'));

        $this->flushHeaders();
        $customer = $this->authToken($fixture['token'])
            ->getJson("/api/v1/customer/payments/{$payment->public_id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount', '100.00');

        $this->assertArrayNotHasKey('gateway_payment_id', $customer->json('data'));
        $this->assertArrayNotHasKey('idempotency_key', $customer->json('data'));
        $this->assertArrayNotHasKey('transactions', $customer->json('data'));
    }

    public function test_admin_index_requires_payments_view(): void
    {
        $this->flushHeaders();
        $customer = $this->makeCustomer();
        $this->authToken($this->issueToken($customer))
            ->getJson('/api/v1/admin/payments')
            ->assertStatus(403);
    }

    /**
     * Additional payment for an existing customer/token (helper for the
     * multi-payment listing scenarios).
     *
     * @return array{payment: \App\Modules\Payments\Infrastructure\Persistence\Models\Payment, session_id: string}
     */
    private function paymentForUser(
        \App\Modules\Identity\Infrastructure\Persistence\Models\User $user,
        string $token,
        string $gateway,
        string $price,
    ): array {
        $variant = $this->makeSellableVariant($price, 5);
        $orderPublicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $sessionId = $gateway === 'tabby'
            ? 'tabby_sess_' . substr(md5($orderPublicId . 'x'), 0, 8)
            : 'tamara_chk_' . substr(md5($orderPublicId . 'x'), 0, 8);

        $this->fakeGatewayHttp([
            'api.tabby.ai/*' => Http::response($this->tabbyPending($sessionId)),
            'api.tamara.co/*' => Http::response($this->tamaraPending($sessionId)),
        ]);

        $response = $this->createIntent($user, $token, $orderPublicId, ['gateway' => $gateway]);
        $response->assertStatus(201);

        $payment = DB::table('payments')->where('public_id', $response->json('data.public_id'))->first();

        return [
            'payment' => $payment,
            'session_id' => $sessionId,
        ];
    }
}
