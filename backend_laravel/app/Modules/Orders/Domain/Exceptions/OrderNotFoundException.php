<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Exceptions;

/**
 * Thrown when an order cannot be found (or is not visible to the
 * requesting customer — ownership scoping resolves both to 404).
 */
class OrderNotFoundException extends OrderDomainException
{
    public static function forPublicId(string $publicId): self
    {
        return new self("Order not found: {$publicId}.");
    }
}
