<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

/**
 * A requested gateway code does not exist in payment_gateways. → HTTP 422.
 */
final class PaymentGatewayException extends PaymentDomainException
{
    public static function unknown(string $code): self
    {
        return new self("Unknown payment gateway '{$code}'.");
    }
}
