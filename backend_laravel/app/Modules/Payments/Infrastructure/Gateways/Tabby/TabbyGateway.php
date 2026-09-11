<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Gateways\Tabby;

use App\Modules\Payments\Domain\Contracts\GatewayResult;
use App\Modules\Payments\Infrastructure\Gateways\AbstractGateway;
use App\Modules\Payments\Infrastructure\Persistence\Models\Installment;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentAttempt;
use App\Modules\Payments\Infrastructure\Persistence\Models\Refund;

/**
 * TabbyGateway — Tabby (v2) checkout integration.
 *
 * Tabby supports 4 installments. Amounts are sent as exact decimal strings
 * (bcmath scale 2) — never floats. All HTTP traffic is isolated here; the
 * application layer only sees GatewayResult.
 *
 * In development/tests no live credentials are required: Http::fake() mocks
 * the responses and config values default to empty strings.
 */
final class TabbyGateway extends AbstractGateway
{
    public function code(): string
    {
        return 'tabby';
    }

    public function createCheckout(Payment $payment, int $userId, ?int $numberOfInstallments = null): GatewayResult
    {
        $body = [
            'payment' => [
                'amount'   => (string) $payment->amount,
                'currency' => $payment->currency,
                'reference' => (string) $payment->public_id,
                'description' => 'Order ' . (string) $payment->order_id,
            ],
            'checkout' => [
                'merchant_code' => (string) ($this->config()['merchant_code'] ?? ''),
                'country_code'  => 'SA',
                'locale'        => 'ar-SA',
                'number_of_installments' => $numberOfInstallments ?? 4,
                'customer'      => ['id' => (string) $userId],
            ],
        ];

        $response = $this->post('/checkout', $body);

        return $this->interpret($response->json() ?? [], $response->successful());
    }

    public function retryCheckout(Payment $payment, PaymentAttempt $attempt): GatewayResult
    {
        $sessionId = (string) ($payment->gateway_payment_id ?? '');

        $response = $this->post("/checkout/{$sessionId}/retry", [
            'payment' => ['amount' => (string) $attempt->amount, 'currency' => $attempt->currency],
        ]);

        return $this->interpret($response->json() ?? [], $response->successful());
    }

    public function authorize(Payment $payment, PaymentAttempt $attempt): GatewayResult
    {
        // Tabby settles at checkout completion; authorize is a no-op hold.
        return $this->queryPaymentStatus($payment);
    }

    public function capture(Payment $payment, PaymentAttempt $attempt): GatewayResult
    {
        return $this->queryPaymentStatus($payment);
    }

    public function cancel(Payment $payment): GatewayResult
    {
        $sessionId = (string) ($payment->gateway_payment_id ?? '');

        $response = $this->post("/checkout/{$sessionId}/cancel", []);
        $data = $response->json() ?? [];

        return $this->normalize(
            data: $data,
            success: $response->successful(),
            transactionId: $this->stringAt($data, 'payment.payment_id'),
            redirectUrl: null,
            failureCode: $response->successful() ? null : 'tabby_cancel_failed',
            failureMessage: $response->successful() ? null : 'Tabby could not cancel the checkout.',
        );
    }

    public function refund(Refund $refund, Payment $payment): GatewayResult
    {
        $paymentRef = (string) ($payment->gateway_payment_id ?? '');

        $response = $this->post("/payments/{$paymentRef}/refunds", [
            'refund' => [
                'amount'    => (string) $refund->amount,
                'currency'  => $refund->currency ?? $payment->currency,
                'reference' => (string) $refund->id,
                'reason'    => $refund->reason,
            ],
        ]);

        $data = $response->json() ?? [];

        return $this->normalize(
            data: $data,
            success: $response->successful(),
            transactionId: $this->stringAt($data, 'id'),
            redirectUrl: null,
            failureCode: $response->successful() ? null : ($this->stringAt($data, 'code') ?? 'tabby_refund_failed'),
            failureMessage: $response->successful() ? null : 'Tabby rejected the refund request.',
        );
    }

    public function queryPaymentStatus(Payment $payment): GatewayResult
    {
        $sessionId = (string) ($payment->gateway_payment_id ?? '');

        $response = $this->get("/checkout/{$sessionId}");

        return $this->interpret($response->json() ?? [], $response->successful());
    }

    public function queryInstallmentStatus(Installment $installment): GatewayResult
    {
        $response = $this->get('/payments', [
            'reference_code' => (string) ($installment->gateway_transaction_id ?? ''),
        ]);

        $data = $response->json() ?? [];

        return $this->interpret($data, $response->successful());
    }

    /**
     * Map a Tabby checkout/payment body into the normalized result.
     *
     * @param array<string, mixed> $data
     */
    private function interpret(array $data, bool $httpOk): GatewayResult
    {
        $status = $this->stringAt($data, 'status');
        $redirectUrl = $this->stringAt($data, 'urls.success_url')
            ?? $this->stringAt($data, 'checkout_url');
        $transactionId = $this->stringAt($data, 'payment.payment_id')
            ?? $this->stringAt($data, 'id')
            ?? $this->stringAt($data, 'checkout_id');

        if (! $httpOk) {
            return $this->normalize(
                data: $data,
                success: false,
                transactionId: $transactionId,
                redirectUrl: null,
                failureCode: $this->stringAt($data, 'code') ?? 'tabby_error',
                failureMessage: $this->stringAt($data, 'message') ?? 'Tabby returned an error.',
            );
        }

        // Checkout creation returns a session awaiting the customer: pending.
        if ($status === null || in_array($status, ['INIT', 'PENDING', 'CREATED'], true)) {
            return new GatewayResult(
                status: GatewayResult::STATUS_PENDING,
                transactionId: $transactionId,
                redirectUrl: $redirectUrl,
                raw: static::redact($data),
            );
        }

        if (in_array($status, ['PAID', 'AUTHORIZED', 'CAPTURED', 'SETTLED'], true)) {
            return $this->normalize($data, true, $transactionId, $redirectUrl);
        }

        if (in_array($status, ['FAILED', 'CANCELLED', 'EXPIRED'], true)) {
            return $this->normalize(
                data: $data,
                success: false,
                transactionId: $transactionId,
                redirectUrl: $redirectUrl,
                failureCode: $this->stringAt($data, 'error_code') ?? 'tabby_' . strtolower($status),
                failureMessage: $this->stringAt($data, 'error_message') ?? "Tabby reported {$status}.",
            );
        }

        return new GatewayResult(
            status: GatewayResult::STATUS_PENDING,
            transactionId: $transactionId,
            redirectUrl: $redirectUrl,
            raw: static::redact($data),
        );
    }
}
