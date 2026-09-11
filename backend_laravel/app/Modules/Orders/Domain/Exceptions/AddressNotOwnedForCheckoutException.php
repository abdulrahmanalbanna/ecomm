<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Exceptions;

/**
 * Thrown when a referenced address (shipping or billing) does not exist or
 * does not belong to the authenticated user. Ownership is enforced at the
 * application layer (RLS is not part of this baseline).
 */
class AddressNotOwnedForCheckoutException extends OrderDomainException
{
    public function __construct(string $message = 'The selected address was not found or does not belong to you.')
    {
        parent::__construct($message);
    }
}
