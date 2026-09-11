<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Exceptions;

/**
 * Thrown when a cart line item references a variant/product that is no longer
 * sellable at checkout time (inactive, unpublished, soft-deleted, or zero price).
 */
class CheckoutItemNotSellableException extends OrderDomainException
{
    public static function forVariant(int $variantId, string $reason): self
    {
        return new self("Item (variant {$variantId}) can no longer be purchased: {$reason}.");
    }
}
