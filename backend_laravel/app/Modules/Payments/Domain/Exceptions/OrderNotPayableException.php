<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

/**
 * The order exists but is not in a state that can receive a payment
 * (only 'pending' orders are payable per the order state machine).
 * → HTTP 422.
 */
final class OrderNotPayableException extends PaymentDomainException
{
    public static function forStatus(string $status): self
    {
        return new self("Order in status '{$status}' is not payable.");
    }
}
