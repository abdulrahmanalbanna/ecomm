<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentsTestHelpers;
use Tests\TestCase;

/**
 * Customer payment retry: new attempt rows, gateway re-invocation, and the
 * state-machine boundary that blocks retrying settled payments.
 */
final class PaymentRetryTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentsTestHelpers;

    public function test_retry_of_failed_payment_creates_attempt_two_and_reopens_checkout(): void
    {
        // A failed payment is retryable and retains its gateway session.
        $fixture = $this->makeFailedPayment('tabby');

        $paymentPublicId = (string) $fixture['payment']->public_id;

        $this->assertSame('failed', (string) $fixture['payment']->status);

        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response($this->tabbyPending('tabby_sess_retried'))]);

        $this->authToken($fixture['token'])
            ->postJson("/api/v1/customer/payments/{$paymentPublicId}/retry")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Payment retry initiated')
            ->assertJsonPath('data.status', 'processing');

        $attempts = DB::table('payment_attempts')
            ->where('payment_id', $fixture['payment']->id)
            ->orderBy('attempt_number')
            ->pluck('attempt_number')
            ->all();

        $this->assertSame([1, 2], array_map('intval', $attempts));

        // The retry re-used the existing gateway session (retry endpoint).
        $retryCall = collect(Http::recorded())->pluck(0)->last();
        $this->assertStringContainsString('/checkout/tabby_sess_' . substr(md5($fixture['order_public_id']), 0, 8) . '/retry', (string) $retryCall->url());
    }

    public function test_retry_of_paid_payment_is_rejected(): void
    {
        $fixture = $this->makePaidPayment('tabby');

        $paymentPublicId = (string) $fixture['payment']->public_id;

        $this->fakeGatewayHttp();

        $this->authToken($fixture['token'])
            ->postJson("/api/v1/customer/payments/{$paymentPublicId}/retry")
            ->assertStatus(422);

        // No extra attempt was recorded for a settled payment.
        $this->assertSame(
            1,
            (int) DB::table('payment_attempts')->where('payment_id', $fixture['payment']->id)->count(),
        );
    }

    public function test_retry_requires_authentication_and_ownership(): void
    {
        $fixture = $this->makeFailedPayment('tabby');

        $paymentPublicId = (string) $fixture['payment']->public_id;

        // The fixture authenticated as the owner; drop persisted headers
        // so the next request is genuinely unauthenticated.
        $this->flushHeaders();

        $this->postJson("/api/v1/customer/payments/{$paymentPublicId}/retry")->assertStatus(401);

        // Another customer cannot see (or retry) it — 404, no existence leak.
        $other = $this->makeCustomer();
        $otherToken = $this->issueToken($other);

        $this->authToken($otherToken)
            ->postJson("/api/v1/customer/payments/{$paymentPublicId}/retry")
            ->assertStatus(404);
    }

    public function test_retry_after_gateway_failure_succeeds_and_syncs_order(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('100.00', 5);
        $orderPublicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        // First attempt: gateway declines.
        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response($this->tabbyDeclined(), 402)]);
        $this->createIntent($user, $token, $orderPublicId)->assertStatus(422);

        $payment = DB::table('payments')->where('order_id', DB::table('orders')->where('public_id', $orderPublicId)->value('id'))->first();
        $this->assertSame('pending', (string) $payment->status);

        // Retry: gateway now accepts (fresh stubs — the decline stub must not
        // shadow this one).
        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response($this->tabbyPending('tabby_sess_ok'))]);

        $this->authToken($token)
            ->postJson("/api/v1/customer/payments/{$payment->public_id}/retry")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'processing');

        $this->assertSame('payment_pending', (string) DB::table('orders')->where('public_id', $orderPublicId)->value('status'));
    }
}
