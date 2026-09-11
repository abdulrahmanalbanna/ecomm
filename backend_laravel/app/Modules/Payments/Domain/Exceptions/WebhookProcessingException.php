<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

/**
 * The webhook event was persisted (audit/replay) but processing failed.
 * The event row keeps processed=false and error_message so the event can
 * be retried/replayed. → HTTP 202 (received, will retry) for the gateway.
 */
final class WebhookProcessingException extends PaymentDomainException
{
    public static function forEvent(string $gatewayEventId, string $reason): self
    {
        return new self("Webhook event '{$gatewayEventId}' could not be processed: {$reason}");
    }
}
