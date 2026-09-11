<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Exceptions;

use RuntimeException;

/**
 * Base exception for all Orders domain errors.
 *
 * Controllers translate these into JSON responses:
 *  - OrderNotFoundException          → 404
 *  - everything else (domain rules)  → 422
 */
class OrderDomainException extends RuntimeException
{
}
