<?php

declare(strict_types=1);

namespace App\Modules\Customer\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a customer profile cannot be found for the authenticated user.
 */
class CustomerProfileNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Customer profile not found.')
    {
        parent::__construct($message);
    }
}
