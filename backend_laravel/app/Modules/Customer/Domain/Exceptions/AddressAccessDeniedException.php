<?php

declare(strict_types=1);

namespace App\Modules\Customer\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a user attempts to access an address they do not own.
 */
class AddressAccessDeniedException extends RuntimeException
{
    public function __construct(string $message = 'You do not have permission to access this address.')
    {
        parent::__construct($message);
    }
}
