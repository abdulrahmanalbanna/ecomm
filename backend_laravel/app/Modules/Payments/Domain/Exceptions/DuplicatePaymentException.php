<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

/**
 * An idempotency key was reused with a DIFFERENT payload (different order,
 * gateway, or method). Reuse with an identical payload returns the original
 * payment instead of throwing. → HTTP 409.
 */
final class DuplicatePaymentException extends PaymentDomainException
{
    public static function keyConflict(string $idempotencyKey): self
    {
        return new self("Idempotency key '{$idempotencyKey}' has already been used for a different payment request.");
    }

    public static function gatewayTransactionConflict(string $gatewayTransactionId): self
    {
        return new self("Gateway transaction '{$gatewayTransactionId}' has already been recorded.");
    }
}
