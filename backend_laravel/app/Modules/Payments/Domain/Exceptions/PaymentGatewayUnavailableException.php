<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

/**
 * Gateway exists but is inactive, or does not support the requested
 * payment method / installment count / currency. → HTTP 422.
 */
final class PaymentGatewayUnavailableException extends PaymentDomainException
{
    public static function inactive(string $code): self
    {
        return new self("Payment gateway '{$code}' is not currently active.");
    }

    public static function unsupportedMethod(string $code, string $method): self
    {
        return new self("Payment gateway '{$code}' does not support payment method '{$method}'.");
    }

    public static function unsupportedInstallments(string $code, int $count): self
    {
        return new self("Payment gateway '{$code}' does not support {$count} installments.");
    }

    public static function unsupportedCurrency(string $code, string $currency): self
    {
        return new self("Payment gateway '{$code}' does not support currency '{$currency}'.");
    }
}
