<?php

declare(strict_types=1);

namespace App\Modules\Customer\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when an address cannot be found within the authenticated user's scope.
 */
class AddressNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Address not found.')
    {
        parent::__construct($message);
    }
}
