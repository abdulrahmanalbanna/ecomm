<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

use RuntimeException;

/**
 * Base class for all Payments domain exceptions.
 *
 * Controllers map these to HTTP statuses locally (mirroring the Orders
 * module pattern — no global render handlers in bootstrap/app.php).
 */
abstract class PaymentDomainException extends RuntimeException
{
}
