<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Exceptions;

/**
 * Thrown when a cancellation is attempted from a state that the DB state
 * machine does not allow to transition to 'cancelled' (or, for customers,
 * from a state outside the customer-cancellable window).
 */
class OrderNotCancellableException extends OrderDomainException
{
    public static function fromStatus(string $status): self
    {
        return new self("Order cannot be cancelled from status '{$status}'.");
    }

    public static function customerWindow(string $status): self
    {
        return new self("Orders can only be cancelled while pending or awaiting payment; current status is '{$status}'.");
    }
}
