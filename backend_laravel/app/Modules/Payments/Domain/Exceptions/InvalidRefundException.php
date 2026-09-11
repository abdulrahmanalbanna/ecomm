<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

/**
 * The refund request violates a business rule (payment not in a refundable
 * state, amount not positive, no settled charge to reverse). → HTTP 422.
 */
final class InvalidRefundException extends PaymentDomainException
{
    public static function notRefundable(string $paymentStatus): self
    {
        return new self("Payment in status '{$paymentStatus}' is not refundable.");
    }

    public static function invalidAmount(string $amount): self
    {
        return new self("Refund amount '{$amount}' must be a positive decimal with at most 2 fractional digits.");
    }

    public static function noSettledCharge(): self
    {
        return new self('No settled charge transaction exists to refund.');
    }
}
