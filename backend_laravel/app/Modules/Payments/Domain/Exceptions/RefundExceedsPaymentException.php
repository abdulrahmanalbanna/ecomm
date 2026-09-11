<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

/**
 * The aggregate of active refunds would exceed the original payment amount.
 * Validated in PHP (friendly 422) AND enforced by the PostgreSQL trigger
 * trg_refunds_validate_total (SQLSTATE 23514) which locks the payment row —
 * the DB remains the final boundary under concurrency.
 */
final class RefundExceedsPaymentException extends PaymentDomainException
{
    public static function forAmounts(string $requested, string $remaining): self
    {
        return new self("Refund of {$requested} exceeds the refundable balance of {$remaining}.");
    }
}
