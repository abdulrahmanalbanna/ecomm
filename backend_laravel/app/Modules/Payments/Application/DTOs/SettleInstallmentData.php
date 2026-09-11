<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\DTOs;

/**
 * Immutable input for SettleInstallmentAction.
 */
final readonly class SettleInstallmentData
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public int $installmentId,
        public string $gatewayTransactionId,
        public ?string $settledAt = null,
        public array $metadata = [],
    ) {
    }
}
