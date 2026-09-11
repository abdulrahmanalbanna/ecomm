<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

class AccountInactiveException extends RuntimeException
{
    public function __construct(string $message = 'Account is inactive, suspended, or deleted.')
    {
        parent::__construct($message, 403);
    }
}
