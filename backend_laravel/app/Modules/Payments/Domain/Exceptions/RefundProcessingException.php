<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

/**
 * The gateway rejected the refund submission. The refund record is marked
 * failed and the payment state is restored/kept consistent. → HTTP 502.
 */
final class RefundProcessingException extends PaymentDomainException
{
    public static function gatewayRejected(?string $reason = null): self
    {
        return new self('The payment gateway rejected the refund request' . ($reason ? ": {$reason}" : '.'));
    }
}
