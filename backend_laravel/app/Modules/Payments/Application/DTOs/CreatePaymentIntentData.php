<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\DTOs;

/**
 * Immutable input for CreatePaymentIntentAction.
 *
 * The amount is NEVER accepted from the client — the PaymentAmountService
 * derives it from the order totals server-side. Only the gateway code,
 * payment method, optional installment count, and a client idempotency key
 * are supplied.
 */
final readonly class CreatePaymentIntentData
{
    public function __construct(
        public string $orderPublicId,
        public int $userId,
        public string $gatewayCode,
        public string $paymentMethod,
        public ?int $numberOfInstallments = null,
        public ?string $idempotencyKey = null,
    ) {
    }
}
