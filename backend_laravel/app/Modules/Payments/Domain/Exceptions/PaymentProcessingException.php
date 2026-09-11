<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

/**
 * The gateway rejected or failed the charge request. The payment intent is
 * NOT corrupted: the attempt is recorded as failed and the intent stays
 * retryable (failed → processing is a legal transition). → HTTP 502.
 */
final class PaymentProcessingException extends PaymentDomainException
{
    public static function declined(?string $code = null, ?string $message = null): self
    {
        $detail = $message ?? $code ?? 'the gateway declined the payment';

        return new self("Payment could not be processed: {$detail}.");
    }

    public static function transport(string $gatewayCode): self
    {
        return new self("Payment gateway '{$gatewayCode}' did not respond. Please retry.");
    }
}
