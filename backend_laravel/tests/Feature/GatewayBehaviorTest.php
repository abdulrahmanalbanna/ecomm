<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\Application\Services\PaymentGatewayResolver;
use App\Modules\Payments\Domain\Exceptions\PaymentGatewayException;
use App\Modules\Payments\Domain\Exceptions\PaymentGatewayUnavailableException;
use App\Modules\Payments\Infrastructure\Gateways\PaymentGatewayRegistry;
use App\Modules\Payments\Infrastructure\Gateways\Tabby\TabbyGateway;
use App\Modules\Payments\Infrastructure\Gateways\Tamara\TamaraGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentsTestHelpers;
use Tests\TestCase;

/**
 * Gateway infrastructure behavior (Task 10, §33-§36):
 *  - the registry maps codes to processors and rejects unknown codes
 *  - AbstractGateway::redact() strips credential/card-like keys recursively
 *    while preserving benign look-alikes (plan_id, shipping_address)
 *  - webhook HMAC verification is constant-time and secret-sourced from env
 *  - transport failures never corrupt payment state
 *  - the resolver refuses inactive gateways and unsupported currencies
 */
final class GatewayBehaviorTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentsTestHelpers;

    public function test_registry_resolves_builtin_processors_by_code(): void
    {
        $registry = new PaymentGatewayRegistry();

        $this->assertSame(['tabby', 'tamara'], $registry->codes());
        $this->assertTrue($registry->has('tabby'));
        $this->assertFalse($registry->has('paypal'));
        $this->assertInstanceOf(TabbyGateway::class, $registry->resolve('tabby'));
        $this->assertInstanceOf(TamaraGateway::class, $registry->resolve('tamara'));
    }

    public function test_registry_rejects_unknown_gateway_code(): void
    {
        $registry = new PaymentGatewayRegistry();

        try {
            $registry->resolve('paypal');
            $this->fail('Expected the registry to reject an unregistered code.');
        } catch (PaymentGatewayException $e) {
            $this->assertSame("Unknown payment gateway 'paypal'.", $e->getMessage());
        }
    }

    public function test_redact_strips_credential_like_keys_recursively(): void
    {
        $redacted = TabbyGateway::redact([
            'status' => 'PAID',
            'api_key' => 'super-secret-value',
            'nested' => [
                'Authorization' => 'Bearer abc.def.ghi',
                'card_number' => '4111111111111111',
                'deeper' => [
                    'security_code' => '123',
                    'amount' => '100.00',
                ],
            ],
        ]);

        $this->assertSame('[REDACTED]', $redacted['api_key']);
        $this->assertSame('[REDACTED]', $redacted['nested']['Authorization']);
        $this->assertSame('[REDACTED]', $redacted['nested']['card_number']);
        $this->assertSame('[REDACTED]', $redacted['nested']['deeper']['security_code']);
        $this->assertSame('PAID', $redacted['status']);
        $this->assertSame('100.00', $redacted['nested']['deeper']['amount']);
    }

    public function test_redact_preserves_benign_lookalike_keys(): void
    {
        // 'plan_id' contains 'pan', 'shipping_address' contains 'pin' — the
        // substring list is deliberately multi-char to avoid these traps.
        $this->assertFalse(TabbyGateway::isSensitiveKey('plan_id'));
        $this->assertFalse(TabbyGateway::isSensitiveKey('shipping_address'));
        $this->assertFalse(TabbyGateway::isSensitiveKey('checkout_id'));
        $this->assertFalse(TabbyGateway::isSensitiveKey('status'));

        // Normalization: separators and case do not hide a real secret key.
        $this->assertTrue(TabbyGateway::isSensitiveKey('API-KEY'));
        $this->assertTrue(TabbyGateway::isSensitiveKey('access_token'));
        $this->assertTrue(TabbyGateway::isSensitiveKey('cvv'));
        $this->assertTrue(TabbyGateway::isSensitiveKey('token'));
    }

    public function test_webhook_signature_verification_is_hmac_sha256_over_raw_body(): void
    {
        $this->configurePaymentGateways();
        $gateway = new TabbyGateway();

        $body = json_encode(['id' => 'evt_sig_1', 'event' => 'payment.paid'], JSON_THROW_ON_ERROR);
        $valid = hash_hmac('sha256', $body, self::PAY_WEBHOOK_SECRET);

        $this->assertTrue($gateway->verifyWebhookSignature($body, $valid));
        // Header comparison is case-insensitive (strtolower before hash_equals).
        $this->assertTrue($gateway->verifyWebhookSignature($body, strtoupper($valid)));
        // Tampered body or bogus/missing signatures must fail.
        $this->assertFalse($gateway->verifyWebhookSignature($body . 'x', $valid));
        $this->assertFalse($gateway->verifyWebhookSignature($body, 'deadbeef'));
        $this->assertFalse($gateway->verifyWebhookSignature($body, null));
        $this->assertFalse($gateway->verifyWebhookSignature($body, ''));

        // No configured secret → verification can never succeed.
        config(['payments.gateways.tabby.webhook_secret' => '']);
        $this->assertFalse((new TabbyGateway())->verifyWebhookSignature($body, $valid));
    }

    public function test_transport_failure_keeps_intent_pending_and_retryable(): void
    {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant('100.00', 5);
        $orderPublicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $this->fakeGatewayHttp(['api.tabby.ai/*' => Http::failedConnection('cURL error 7')]);

        $this->createIntent($user, $token, $orderPublicId)
            ->assertStatus(422)
            ->assertJsonPath('message', "Payment gateway 'tabby' did not respond. Please retry.");

        // The exception escaped BEFORE any outcome was recorded: the intent
        // stays 'pending' with a still-pending attempt #1 — fully retryable.
        $payment = DB::table('payments')
            ->where('order_id', DB::table('orders')->where('public_id', $orderPublicId)->value('id'))
            ->first();
        $this->assertNotNull($payment);
        $this->assertSame('pending', (string) $payment->status);
        $this->assertSame(
            'pending',
            (string) DB::table('payment_attempts')->where('payment_id', $payment->id)->value('status')
        );
    }

    public function test_resolver_rejects_inactive_gateway(): void
    {
        /** @var PaymentGatewayResolver $resolver */
        $resolver = $this->app->make(PaymentGatewayResolver::class);

        DB::table('payment_gateways')->where('code', 'tabby')->update(['is_active' => false]);

        try {
            $resolver->resolveRow('tabby');
            $this->fail('Expected the resolver to reject an inactive gateway.');
        } catch (PaymentGatewayUnavailableException $e) {
            $this->assertSame("Payment gateway 'tabby' is not currently active.", $e->getMessage());
        }
    }

    public function test_resolver_rejects_unsupported_currency(): void
    {
        /** @var PaymentGatewayResolver $resolver */
        $resolver = $this->app->make(PaymentGatewayResolver::class);

        $this->expectException(PaymentGatewayUnavailableException::class);
        $this->expectExceptionMessage("Payment gateway 'tabby' does not support currency 'USD'.");

        $resolver->resolveForPayment('tabby', 'full', null, 'USD');
    }
}
