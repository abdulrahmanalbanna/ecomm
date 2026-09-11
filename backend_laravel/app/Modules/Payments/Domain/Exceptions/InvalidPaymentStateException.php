<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

/**
 * Illegal payment status transition (PHP adjacency map and/or the
 * PostgreSQL trigger trg_payments_status_transition, SQLSTATE P0005).
 * → HTTP 422.
 */
final class InvalidPaymentStateException extends PaymentDomainException
{
    public static function between(string $from, string $to): self
    {
        return new self("Invalid payment status transition: {$from} \u{2192} {$to}.");
    }

    public static function notPayable(string $status): self
    {
        return new self("Payment cannot be initiated or retried from status '{$status}'.");
    }
}
