<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\DTOs;

/**
 * Immutable input for RetryPaymentAction.
 */
final readonly class RetryPaymentData
{
    public function __construct(
        public string $paymentPublicId,
        public int $userId,
        public ?string $idempotencyKey = null,
    ) {
    }
}
