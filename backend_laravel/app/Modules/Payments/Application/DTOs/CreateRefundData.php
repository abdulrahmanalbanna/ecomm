<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\DTOs;

/**
 * Immutable input for CreateRefundAction.
 *
 * The amount arrives as an exact decimal string and is validated against the
 * refundable balance (RefundBalanceService) before any gateway call. The DB
 * trigger trg_refunds_validate_total remains the final authority.
 */
final readonly class CreateRefundData
{
    public function __construct(
        public string $paymentPublicId,
        public string $amount,
        public ?string $reason = null,
        public ?int $initiatedBy = null,
        public ?string $idempotencyKey = null,
    ) {
    }
}
