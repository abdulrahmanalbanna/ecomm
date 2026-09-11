<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Shared fixtures for the Payments module feature tests.
 *
 * Composes OrdersTestHelpers (users, tokens, catalog, checkout) and adds:
 *  - gateway HTTP fakes matching the exact Tabby/Tamara response shapes the
 *    gateways' interpret() methods consume,
 *  - signed webhook delivery (HMAC over the EXACT raw bytes posted, so the
 *    controller's verification sees the same body that was signed),
 *  - ready-made payment fixtures (processing / paid).
 *
 * All monetary assertions use decimal strings — never floats.
 */
trait PaymentsTestHelpers
{
    use OrdersTestHelpers;

    protected const PAY_WEBHOOK_SECRET = 'test-webhook-secret';

    // ------------------------------------------------------------------
    // Gateway configuration + HTTP faking
    // ------------------------------------------------------------------

    /**
     * Non-empty webhook secrets and api keys for the test run. Real values
     * live only in env; tests inject fakes here (never committed secrets).
     */
    protected function configurePaymentGateways(): void
    {
        config([
            'payments.gateways.tabby.api_key' => 'test-tabby-key',
            'payments.gateways.tabby.webhook_secret' => self::PAY_WEBHOOK_SECRET,
            'payments.gateways.tamara.api_key' => 'test-tamara-key',
            'payments.gateways.tamara.webhook_secret' => self::PAY_WEBHOOK_SECRET,
        ]);
    }

    /**
     * @param array<string, mixed> $map Http::fake stub map (pattern => response)
     */
    protected function fakeGatewayHttp(array $map = []): void
    {
        $this->configurePaymentGateways();

        // Http::fake() MERGES stubs across calls and the first registered
        // match wins, so a fixture-registered stub would otherwise shadow a
        // later per-test stub. Swap in a fresh factory for a clean slate.
        Http::clearResolvedInstance();
        $this->app->instance(
            \Illuminate\Http\Client\Factory::class,
            new \Illuminate\Http\Client\Factory($this->app->make('events')),
        );

        Http::fake($map);
    }

    // ------------------------------------------------------------------
    // Gateway response shapes (exactly what interpret() reads)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    protected function tabbyPending(string $sessionId = 'tabby_sess_1'): array
    {
        return [
            'status' => 'INIT',
            'checkout_id' => $sessionId,
            'urls' => [
                'web' => 'https://checkout.test/tabby/' . $sessionId,
                'success_url' => 'https://checkout.test/tabby/' . $sessionId . '/success',
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function tabbySuccess(string $paymentId = 'tabby_txn_1'): array
    {
        return [
            'status' => 'PAID',
            'payment' => ['payment_id' => $paymentId],
        ];
    }

    /** @return array<string, mixed> */
    protected function tabbyRefundAccepted(string $refundId = 'tabby_ref_1'): array
    {
        return ['id' => $refundId, 'status' => 'CREATED'];
    }

    /** @return array<string, mixed> */
    protected function tabbyDeclined(): array
    {
        return ['code' => 'payment_declined', 'message' => 'Tabby declined the payment.'];
    }

    /** @return array<string, mixed> */
    protected function tamaraPending(string $checkoutId = 'tamara_chk_1'): array
    {
        return [
            'checkoutId' => $checkoutId,
            'status' => 'VerificationPending',
            'checkoutUrl' => 'https://checkout.test/tamara/' . $checkoutId,
        ];
    }

    /** @return array<string, mixed> */
    protected function tamaraSuccess(string $paymentId = 'tamara_pay_1'): array
    {
        return ['paymentId' => $paymentId, 'status' => 'Captured', 'isSuccessful' => 'true'];
    }

    /** @return array<string, mixed> */
    protected function tamaraRefundAccepted(string $refundId = 'tamara_ref_1'): array
    {
        return ['refundId' => $refundId, 'isSuccessful' => 'true'];
    }

    // ------------------------------------------------------------------
    // Webhook delivery (signed against the exact raw body)
    // ------------------------------------------------------------------

    /**
     * POST a gateway webhook with a valid HMAC-SHA256 signature over the raw
     * JSON body. Pass $signature to deliver an INVALID one deliberately.
     *
     * @param array<string, mixed> $payload
     */
    protected function postSignedWebhook(string $code, array $payload, ?string $signature = null): TestResponse
    {
        $this->configurePaymentGateways();

        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $sig = $signature ?? hash_hmac('sha256', (string) $raw, self::PAY_WEBHOOK_SECRET);

        $headerKey = match ($code) {
            'tamara' => 'HTTP_X_TAMARA_SIGNATURE',
            default => 'HTTP_T_SIGNATURE',
        };

        return $this->call(
            'POST',
            '/api/v1/webhooks/' . $code,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', $headerKey => $sig],
            (string) $raw,
        );
    }

    /** @param array<string, mixed> $payload */
    protected function postUnsignedWebhook(string $code, array $payload): TestResponse
    {
        $this->configurePaymentGateways();

        return $this->call(
            'POST',
            '/api/v1/webhooks/' . $code,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
        );
    }

    protected function uniqueEventId(string $prefix = 'evt'): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }

    // ------------------------------------------------------------------
    // Payment fixtures
    // ------------------------------------------------------------------

    /**
     * POST /api/v1/customer/payments with sensible defaults.
     *
     * @param array<string, mixed> $overrides
     */
    protected function createIntent(User $user, string $token, string $orderPublicId, array $overrides = []): TestResponse
    {
        return $this->authToken($token)->postJson('/api/v1/customer/payments', array_merge([
            'order_public_id' => $orderPublicId,
            'gateway' => 'tabby',
            'payment_method' => 'full',
        ], $overrides));
    }

    /**
     * Order + payment intent (status: processing, order: payment_pending).
     *
     * @param array<string, mixed> $intentOverrides
     * @return array{user: User, token: string, payment: Payment, order_public_id: string, session_id: string}
     */
    protected function makeProcessingPayment(
        string $gateway = 'tabby',
        ?string $price = null,
        array $intentOverrides = [],
    ): array {
        $user = $this->makeCustomer();
        $token = $this->issueToken($user);
        $variant = $this->makeSellableVariant($price ?? '100.00', 5);
        $orderPublicId = $this->placeOrder($user, $token, [['variant_id' => $variant->id, 'quantity' => 1]]);

        $sessionId = $gateway === 'tabby' ? 'tabby_sess_' . substr(md5($orderPublicId), 0, 8) : 'tamara_chk_' . substr(md5($orderPublicId), 0, 8);

        $fake = $gateway === 'tabby'
            ? ['api.tabby.ai/*' => Http::response($this->tabbyPending($sessionId))]
            : ['api.tamara.co/*' => Http::response($this->tamaraPending($sessionId))];

        $this->fakeGatewayHttp($fake);

        $response = $this->createIntent($user, $token, $orderPublicId, array_merge(['gateway' => $gateway], $intentOverrides));
        $response->assertStatus(201);

        $payment = Payment::where('public_id', $response->json('data.public_id'))->firstOrFail();

        return [
            'user' => $user,
            'token' => $token,
            'payment' => $payment,
            'order_public_id' => $orderPublicId,
            'session_id' => $sessionId,
        ];
    }

    /**
     * Processing payment + signed payment.failed webhook → status failed
     * (retryable, gateway session retained) while the order stays in the
     * payment window.
     *
     * @return array{user: User, token: string, payment: Payment, order_public_id: string, session_id: string}
     */
    protected function makeFailedPayment(string $gateway = 'tabby', ?string $price = null): array
    {
        $fixture = $this->makeProcessingPayment($gateway, $price);

        $this->postSignedWebhook($gateway, [
            'id' => $this->uniqueEventId('fail'),
            'event' => 'payment.failed',
            'payment_id' => $fixture['session_id'],
        ])->assertStatus(200);

        $fixture['payment'] = $fixture['payment']->fresh();

        return $fixture;
    }

    /**
     * Processing payment + signed payment.paid webhook → status paid, order
     * confirmed, settled charge transaction on the ledger.
     *
     * @return array{user: User, token: string, payment: Payment, order_public_id: string, session_id: string}
     */
    protected function makePaidPayment(string $gateway = 'tabby', ?string $price = null): array
    {
        $fixture = $this->makeProcessingPayment($gateway, $price);

        $webhook = $this->postSignedWebhook($gateway, [
            'id' => $this->uniqueEventId(),
            'event' => 'payment.paid',
            'payment_id' => $fixture['session_id'],
        ]);

        $webhook->assertStatus(200)->assertJsonPath('effect', 'payment_paid');

        $fixture['payment'] = $fixture['payment']->fresh();

        return $fixture;
    }

    /**
     * Staff user with payments permissions + token.
     *
     * @param list<string> $permissions
     */
    protected function makePaymentStaff(array $permissions = ['payments.view']): array
    {
        $staff = $this->makeStaff('staff', $permissions);

        return [$staff, $this->issueToken($staff)];
    }

    protected function paymentRow(string $publicId): Payment
    {
        return Payment::where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function webhookEventRow(string $gatewayEventId): ?array
    {
        $row = DB::table('payment_webhook_events')->where('gateway_event_id', $gatewayEventId)->first();

        return $row === null ? null : (array) $row;
    }
}
