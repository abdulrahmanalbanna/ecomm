<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

/**
 * An order already has a payment intent (payments.order_id is UNIQUE).
 * The customer must use the retry endpoint instead. → HTTP 409.
 */
final class PaymentAlreadyExistsException extends PaymentDomainException
{
    public static function forOrder(string $orderPublicId): self
    {
        return new self("A payment already exists for order '{$orderPublicId}'. Use the retry endpoint instead.");
    }
}
