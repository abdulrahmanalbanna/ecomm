<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentsTestHelpers;
use Tests\TestCase;

/**
 * Payment intent creation (Tabby + Tamara) through the customer API.
 *
 * Proves: server-derived amounts (never client-supplied), one intent per
 * order, gateway session capture, sanitized gateway_response, order sync to
 * payment_pending, and validation/RBAC boundaries.
 */
final class PaymentIntentCreationTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentsTestHelpers;

    public function test_customer_creates_tabby_intent_and_order_moves_to_payment_pending(): void
    {
        $fixture = $this->makeProcessingPayment('tabby');

        $payment = $fixture['payment'];
        $this->assertSame('tabby', (string) $payment->gateway->code);
        $this->assertSame('processing', (string) $payment->status);
        $this->assertSame('full', (string) $payment->payment_method);
        $this->assertSame('SAR', (string) $payment->currency);
        $this->assertSame($fixture['session_id'], (string) $payment->gateway_payment_id);

        // Amount is derived from the ORDER total, never from the client.
        $orderTotal = (string) DB::table('orders')->where('public_id', $fixture['order_public_id'])->value('total_amount');
        $this->assertSame($orderTotal, (string) $payment->amount);

        // Attempt #1 recorded.
        $attempt = DB::table('payment_attempts')->where('payment_id', $payment->id)->orderBy('attempt_number')->first();
        $this->assertNotNull($attempt);
        $this->assertSame(1, (int) $attempt->attempt_number);
        $this->assertSame($orderTotal, (string) $attempt->amount);

        // Order synced to payment_pending.
        $this->assertSame('payment_pending', (string) DB::table('orders')->where('public_id', $fixture['order_public_id'])->value('status'));
    }

    public function test_intent_response_is_customer_safe_with_redirect_url(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('150.00', 5);
        $orderPublicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response($this->tabbyPending('tabby_sess_safe'))]);

        $response = $this->createIntent($user, $token, $orderPublicId)
            ->assertStatus(201)
            ->assertJsonPath('message', 'Payment intent created')
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.gateway.code', 'tabby')
            ->assertJsonPath('data.redirect_url', 'https://checkout.test/tabby/tabby_sess_safe');

        // Customer-safe: no internal ids, no idempotency key, no raw gateway JSON.
        $data = $response->json('data');
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('idempotency_key', $data);
        $this->assertArrayNotHasKey('gateway_response', $data);
        $this->assertArrayNotHasKey('gateway_payment_id', $data);
        $this->assertArrayNotHasKey('attempts', $data);
        $this->assertArrayNotHasKey('transactions', $data);
    }

    public function test_gateway_response_is_stored_but_never_contains_credentials(): void
    {
        $fixture = $this->makeProcessingPayment('tabby');

        $response = $fixture['payment']->gateway_response;

        $this->assertIsArray($response);
        $this->assertSame('tabby_sess_' . substr(md5($fixture['order_public_id']), 0, 8), (string) $response['checkout_id']);
        $this->assertStringNotContainsString('test-tabby-key', json_encode($response, JSON_THROW_ON_ERROR));
    }

    public function test_tamara_intent_uses_tamara_session_and_checkout_url(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('90.00', 5);
        $orderPublicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->fakeGatewayHttp(['api.tamara.co/*' => Http::response($this->tamaraPending('tamara_chk_9'))]);

        $this->createIntent($user, $token, $orderPublicId, ['gateway' => 'tamara'])
            ->assertStatus(201)
            ->assertJsonPath('data.gateway.code', 'tamara')
            ->assertJsonPath('data.redirect_url', 'https://checkout.test/tamara/tamara_chk_9');

        $payment = Payment::where('order_id', DB::table('orders')->where('public_id', $orderPublicId)->value('id'))->firstOrFail();
        $this->assertSame('tamara_chk_9', (string) $payment->gateway_payment_id);
    }

    public function test_gateway_http_call_carries_server_amount_and_bearer_auth(): void
    {
        $fixture = $this->makeProcessingPayment('tabby');

        $sent = collect(Http::recorded())->pluck(0)->first(
            static fn ($request): bool => str_contains((string) $request->url(), 'api.tabby.ai')
        );

        $this->assertNotNull($sent);
        $this->assertSame('https://api.tabby.ai/api/v2/checkout', (string) $sent->url());
        $this->assertSame((string) $fixture['payment']->amount, (string) $sent->data()['payment']['amount']);
        $this->assertSame('SAR', (string) $sent->data()['payment']['currency']);
        $this->assertSame((string) $fixture['payment']->public_id, (string) $sent->data()['payment']['reference']);
        $this->assertSame('Bearer test-tabby-key', (string) $sent->header('Authorization')[0]);
    }

    public function test_declined_gateway_persists_failed_attempt_and_keeps_payment_pending(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('120.00', 5);
        $orderPublicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response($this->tabbyDeclined(), 402)]);

        $this->createIntent($user, $token, $orderPublicId)->assertStatus(422);

        $payment = Payment::where('order_id', DB::table('orders')->where('public_id', $orderPublicId)->value('id'))->firstOrFail();
        $this->assertSame('pending', (string) $payment->status);

        $attempt = DB::table('payment_attempts')->where('payment_id', $payment->id)->first();
        $this->assertSame('failed', (string) $attempt->status);
        $this->assertSame('payment_declined', (string) $attempt->failure_code);
    }

    public function test_client_cannot_inject_amount_into_the_intent(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('200.00', 5);
        $orderPublicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::response($this->tabbyPending())]);

        $this->createIntent($user, $token, $orderPublicId, ['amount' => '0.01'])
            ->assertStatus(201);

        $payment = Payment::where('order_id', DB::table('orders')->where('public_id', $orderPublicId)->value('id'))->firstOrFail();
        $this->assertSame('200.00', (string) $payment->amount);
    }

    public function test_validation_rejects_bad_payloads(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('100.00', 5);
        $orderPublicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->fakeGatewayHttp();

        // Missing order reference.
        $this->authToken($token)->postJson('/api/v1/customer/payments', [
            'gateway' => 'tabby', 'payment_method' => 'full',
        ])->assertStatus(422)->assertJsonValidationErrors('order_public_id');

        // Unknown gateway.
        $this->createIntent($user, $token, $orderPublicId, ['gateway' => 'paypal'])
            ->assertStatus(422)->assertJsonValidationErrors('gateway');

        // Installment method without a count.
        $this->createIntent($user, $token, $orderPublicId, ['payment_method' => 'installment'])
            ->assertStatus(422)->assertJsonValidationErrors('number_of_installments');

        // Count outside the allowed set.
        $this->createIntent($user, $token, $orderPublicId, [
            'payment_method' => 'installment', 'number_of_installments' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('number_of_installments');
    }

    public function test_gateway_rejects_installment_counts_it_does_not_support(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('100.00', 5);
        $orderPublicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->fakeGatewayHttp();

        // Tabby supports 4 only.
        $this->createIntent($user, $token, $orderPublicId, [
            'payment_method' => 'installment', 'number_of_installments' => 3,
        ])->assertStatus(422);

        // Tamara supports 3/6 only.
        $this->createIntent($user, $token, $orderPublicId, [
            'gateway' => 'tamara', 'payment_method' => 'installment', 'number_of_installments' => 4,
        ])->assertStatus(422);
    }

    public function test_intent_requires_authentication(): void
    {
        $this->postJson('/api/v1/customer/payments', [
            'order_public_id' => '00000000-0000-4000-8000-000000000000',
            'gateway' => 'tabby',
            'payment_method' => 'full',
        ])->assertStatus(401);
    }

    public function test_cancelled_order_is_not_payable(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('100.00', 5);
        $orderPublicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->authToken($token)->postJson("/api/v1/customer/orders/{$orderPublicId}/cancel", [
            'reason' => 'changed mind',
        ])->assertStatus(200);

        $this->fakeGatewayHttp();

        $this->createIntent($user, $token, $orderPublicId)->assertStatus(422);
    }
}
