<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Contracts;

use App\Modules\Payments\Infrastructure\Persistence\Models\Installment;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentAttempt;
use App\Modules\Payments\Infrastructure\Persistence\Models\Refund;

/**
 * PaymentGatewayInterface — the domain contract every gateway processor
 * (Tabby, Tamara, future providers) must satisfy.
 *
 * Controllers and Actions depend ONLY on this contract (resolved through
 * PaymentGatewayRegistry / PaymentGatewayResolver). No gateway-specific
 * HTTP logic may exist outside Infrastructure/Gateways.
 *
 * All amounts are exact decimal STRINGS (bcmath scale 2) — never floats.
 */
interface PaymentGatewayInterface
{
    /**
     * Machine code matching payment_gateways.code ('tabby', 'tamara').
     */
    public function code(): string;

    /**
     * Create a checkout session / payment intent at the gateway.
     *
     * @param int|null $numberOfInstallments required for installment payments
     */
    public function createCheckout(Payment $payment, int $userId, ?int $numberOfInstallments = null): GatewayResult;

    /**
     * Retry/continue an existing checkout for a new attempt (same session id).
     */
    public function retryCheckout(Payment $payment, PaymentAttempt $attempt): GatewayResult;

    /**
     * Authorize (pre-auth hold) — supported for completeness of the contract.
     */
    public function authorize(Payment $payment, PaymentAttempt $attempt): GatewayResult;

    /**
     * Capture a previously authorized payment.
     */
    public function capture(Payment $payment, PaymentAttempt $attempt): GatewayResult;

    /**
     * Cancel/void an uncaptured payment or checkout session.
     */
    public function cancel(Payment $payment): GatewayResult;

    /**
     * Submit a refund to the gateway. Settlement is confirmed asynchronously
     * via webhook — a non-failed response means "accepted", NOT "processed".
     */
    public function refund(Refund $refund, Payment $payment): GatewayResult;

    /**
     * Query the gateway for the current status of a payment (reconciliation).
     */
    public function queryPaymentStatus(Payment $payment): GatewayResult;

    /**
     * Query the gateway for the status of an individual installment charge.
     */
    public function queryInstallmentStatus(Installment $installment): GatewayResult;

    /**
     * Verify an inbound webhook request's authenticity (HMAC signature over
     * the raw body using the gateway's webhook secret from env config).
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool;
}
