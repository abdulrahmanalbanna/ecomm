<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Gateways\Tamara;

use App\Modules\Payments\Domain\Contracts\GatewayResult;
use App\Modules\Payments\Infrastructure\Gateways\AbstractGateway;
use App\Modules\Payments\Infrastructure\Persistence\Models\Installment;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentAttempt;
use App\Modules\Payments\Infrastructure\Persistence\Models\Refund;

/**
 * TamaraGateway — Tamara (v1) checkout integration.
 *
 * Tamara supports 3 and 6 installments (and pay-in-30). Amounts are sent as
 * exact decimal strings (bcmath scale 2) — never floats. The gateway flow is:
 *   1. POST /checkout            → checkoutId (status VerificationPending)
 *   2. customer authorizes       → webhook / request-payment
 *   3. POST /request-payment     → paymentId (status Captured)
 *
 * All HTTP traffic is isolated here; the application layer only sees
 * GatewayResult. In development/tests Http::fake() mocks responses and no
 * live credentials are required.
 */
final class TamaraGateway extends AbstractGateway
{
    public function code(): string
    {
        return 'tamara';
    }

    public function createCheckout(Payment $payment, int $userId, ?int $numberOfInstallments = null): GatewayResult
    {
        $body = [
            'cart' => [
                'amount'   => (string) $payment->amount,
                'currency' => $payment->currency,
            ],
            'order' => [
                'number' => (string) $payment->public_id,
                'total'  => (string) $payment->amount,
                'tax'    => '0.00',
                'shipping' => '0.00',
                'discount' => '0.00',
            ],
            'payments' => [
                'installments' => ['count' => $numberOfInstallments ?? 3],
            ],
            'customer' => ['id' => (string) $userId],
            'description' => 'Order ' . (string) $payment->order_id,
            'notification_url' => (string) ($this->config()['notification_url'] ?? ''),
        ];

        $response = $this->post('/checkout', $body);

        return $this->interpret($response->json() ?? [], $response->successful());
    }

    public function retryCheckout(Payment $payment, PaymentAttempt $attempt): GatewayResult
    {
        // Tamara re-uses the checkout token; a retry re-requests payment on
        // the existing checkout. Without a session there is nothing to retry.
        if (($payment->gateway_payment_id ?? '') === '') {
            return new GatewayResult(
                status: GatewayResult::STATUS_FAILED,
                failureCode: 'tamara_no_checkout',
                failureMessage: 'Tamara has no checkout session to retry.',
                raw: [],
            );
        }

        return $this->capture($payment, $attempt);
    }

    public function authorize(Payment $payment, PaymentAttempt $attempt): GatewayResult
    {
        // Authorization happens on Tamara's hosted page; we observe it via
        // status polling / webhooks rather than an explicit API call.
        return $this->queryPaymentStatus($payment);
    }

    public function capture(Payment $payment, PaymentAttempt $attempt): GatewayResult
    {
        $checkoutId = (string) ($payment->gateway_payment_id ?? '');

        $response = $this->post('/request-payment', [
            'checkoutId'      => $checkoutId,
            'paymentMethod'   => (string) ($this->config()['payment_method'] ?? 'card'),
            'installmentCount' => (int) ($payment->installmentPlan?->number_of_installments ?? 0) ?: null,
        ]);

        return $this->interpret($response->json() ?? [], $response->successful());
    }

    public function cancel(Payment $payment): GatewayResult
    {
        $checkoutId = (string) ($payment->gateway_payment_id ?? '');

        $response = $this->post("/cancel/{$checkoutId}", []);
        $data = $response->json() ?? [];

        return $this->normalize(
            data: $data,
            success: $response->successful() && ($this->stringAt($data, 'isSuccessful') !== 'false'),
            transactionId: $this->stringAt($data, 'paymentId'),
            redirectUrl: null,
            failureCode: $response->successful() ? null : 'tamara_cancel_failed',
            failureMessage: $response->successful() ? null : 'Tamara could not cancel the checkout.',
        );
    }

    public function refund(Refund $refund, Payment $payment): GatewayResult
    {
        $paymentRef = (string) ($payment->gateway_payment_id ?? '');

        $response = $this->post('/refunds', [
            'paymentId' => $paymentRef,
            'amount'    => (string) $refund->amount,
            'currency'  => $refund->currency ?? $payment->currency,
            'reason'    => $refund->reason,
        ]);

        $data = $response->json() ?? [];

        return $this->normalize(
            data: $data,
            success: $response->successful() && ($this->stringAt($data, 'isSuccessful') !== 'false'),
            transactionId: $this->stringAt($data, 'refundId'),
            redirectUrl: null,
            failureCode: $response->successful() ? null : ($this->stringAt($data, 'code') ?? 'tamara_refund_failed'),
            failureMessage: $response->successful() ? null : ($this->stringAt($data, 'userFriendlyVerificationMessage') ?? 'Tamara rejected the refund request.'),
        );
    }

    public function queryPaymentStatus(Payment $payment): GatewayResult
    {
        $ref = (string) ($payment->gateway_payment_id ?? '');

        $response = $this->get("/payment-status/{$ref}");

        return $this->interpret($response->json() ?? [], $response->successful());
    }

    public function queryInstallmentStatus(Installment $installment): GatewayResult
    {
        $ref = (string) ($installment->gateway_transaction_id ?? '');

        $response = $this->get("/payment-status/{$ref}");

        return $this->interpret($response->json() ?? [], $response->successful());
    }

    /**
     * Map a Tamara response body into the normalized result.
     *
     * Tamara statuses: VerificationPending, Authorized, Captured, Cancelled,
     * Expired, Declined, Refunded, PartiallyRefunded.
     *
     * @param array<string, mixed> $data
     */
    private function interpret(array $data, bool $httpOk): GatewayResult
    {
        $status = $this->stringAt($data, 'status');
        $isSuccessful = $this->stringAt($data, 'isSuccessful');
        $transactionId = $this->stringAt($data, 'paymentId')
            ?? $this->stringAt($data, 'checkoutId')
            ?? $this->stringAt($data, 'refundId');
        $redirectUrl = $this->stringAt($data, 'checkoutUrl');

        if (! $httpOk || $isSuccessful === 'false') {
            return $this->normalize(
                data: $data,
                success: false,
                transactionId: $transactionId,
                redirectUrl: null,
                failureCode: $this->stringAt($data, 'code') ?? 'tamara_error',
                failureMessage: $this->stringAt($data, 'userFriendlyVerificationMessage') ?? 'Tamara returned an error.',
            );
        }

        if ($status === null || in_array($status, ['VerificationPending', 'Pending', 'Created'], true)) {
            return new GatewayResult(
                status: GatewayResult::STATUS_PENDING,
                transactionId: $transactionId,
                redirectUrl: $redirectUrl,
                raw: static::redact($data),
            );
        }

        if (in_array($status, ['Authorized'], true)) {
            return new GatewayResult(
                status: GatewayResult::STATUS_PENDING,
                transactionId: $transactionId,
                redirectUrl: $redirectUrl,
                raw: static::redact($data),
            );
        }

        if (in_array($status, ['Captured', 'Settled', 'Paid', 'Refunded', 'PartiallyRefunded'], true)) {
            return $this->normalize($data, true, $transactionId, $redirectUrl);
        }

        if (in_array($status, ['Cancelled', 'Expired', 'Declined', 'Failed'], true)) {
            return $this->normalize(
                data: $data,
                success: false,
                transactionId: $transactionId,
                redirectUrl: $redirectUrl,
                failureCode: 'tamara_' . strtolower($status),
                failureMessage: "Tamara reported {$status}.",
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
