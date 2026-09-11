<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\DTOs;

/**
 * Immutable input for ReconcilePaymentAction.
 */
final readonly class ReconcilePaymentData
{
    public function __construct(
        public string $paymentPublicId,
        public ?int $performedBy = null,
    ) {
    }
}
