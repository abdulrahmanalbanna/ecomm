<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentsTestHelpers;
use Tests\TestCase;

/**
 * Webhook security: HMAC signature verification happens BEFORE anything is
 * persisted. Unsigned, tampered, or malformed deliveries are rejected with
 * 400 and leave no trace in payment_webhook_events.
 */
final class PaymentWebhookSecurityTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentsTestHelpers;

    public function test_unsigned_webhook_is_rejected_and_nothing_persisted(): void
    {
        $this->postUnsignedWebhook('tabby', [
            'id' => $this->uniqueEventId(),
            'event' => 'payment.paid',
            'payment_id' => 'tabby_sess_x',
        ])->assertStatus(400);

        $this->assertSame(0, (int) DB::table('payment_webhook_events')->count());
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $payload = [
            'id' => $this->uniqueEventId(),
            'event' => 'payment.paid',
            'payment_id' => 'tabby_sess_x',
        ];

        $this->postSignedWebhook('tabby', $payload, str_repeat('0', 64))->assertStatus(400);

        $this->assertSame(0, (int) DB::table('payment_webhook_events')->count());
    }

    public function test_missing_signature_header_is_rejected(): void
    {
        $payload = json_encode(['id' => 'evt_x', 'event' => 'payment.paid']);

        $this->call(
            'POST',
            '/api/v1/webhooks/tabby',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $payload,
        )->assertStatus(400);
    }

    public function test_malformed_json_body_is_rejected(): void
    {
        $this->configurePaymentGateways();

        $this->call(
            'POST',
            '/api/v1/webhooks/tabby',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_T_SIGNATURE' => 'deadbeef'],
            'not-json{',
        )->assertStatus(400);
    }

    public function test_event_without_id_is_rejected(): void
    {
        $this->postSignedWebhook('tabby', ['event' => 'payment.paid'])->assertStatus(400);
    }

    public function test_event_without_type_is_rejected(): void
    {
        $this->postSignedWebhook('tabby', ['id' => $this->uniqueEventId()])->assertStatus(400);
    }

    public function test_tamara_uses_its_own_signature_header(): void
    {
        // A tabby-signed header must NOT validate a tamara delivery.
        $payload = [
            'id' => $this->uniqueEventId(),
            'event' => 'payment.paid',
            'paymentId' => 'tamara_chk_x',
        ];

        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig = hash_hmac('sha256', (string) $raw, self::PAY_WEBHOOK_SECRET);

        $this->configurePaymentGateways();

        // Wrong header name (t-signature instead of x-tamara-signature) → 400.
        $this->call(
            'POST',
            '/api/v1/webhooks/tamara',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_T_SIGNATURE' => $sig],
            (string) $raw,
        )->assertStatus(400);

        // Correct header → accepted (unknown reference → 202, persisted).
        $this->call(
            'POST',
            '/api/v1/webhooks/tamara',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TAMARA_SIGNATURE' => $sig],
            (string) $raw,
        )->assertStatus(202);
    }

    public function test_empty_webhook_secret_rejects_everything(): void
    {
        config(['payments.gateways.tabby.webhook_secret' => '']);

        $payload = ['id' => $this->uniqueEventId(), 'event' => 'payment.paid'];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig = hash_hmac('sha256', (string) $raw, self::PAY_WEBHOOK_SECRET);

        $this->call(
            'POST',
            '/api/v1/webhooks/tabby',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_T_SIGNATURE' => $sig],
            (string) $raw,
        )->assertStatus(400);

        $this->assertSame(0, (int) DB::table('payment_webhook_events')->count());
    }
}
