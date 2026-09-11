<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\DTOs;

/**
 * Immutable input for SettlePaymentTransactionAction.
 *
 * Records a settled financial movement (charge/refund/chargeback/adjustment)
 * against a payment. gateway_transaction_id is UNIQUE — re-settling the same
 * gateway transaction is a no-op (idempotent).
 */
final readonly class SettleTransactionData
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public int $paymentId,
        public ?int $attemptId,
        public string $transactionType,
        public string $gatewayTransactionId,
        public string $amount,
        public string $currency,
        public ?string $settledAt = null,
        public array $metadata = [],
    ) {
    }
}
