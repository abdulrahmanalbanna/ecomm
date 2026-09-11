<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

/**
 * The inbound webhook failed verification or structural validation. The
 * payload is NOT persisted (an unauthenticated caller must not be able to
 * fill the audit table). → HTTP 400.
 */
final class InvalidWebhookException extends PaymentDomainException
{
    public static function badSignature(string $gatewayCode): self
    {
        return new self("Webhook signature verification failed for gateway '{$gatewayCode}'.");
    }

    public static function missingEventId(): self
    {
        return new self('Webhook payload is missing a gateway event identifier.');
    }

    public static function missingType(): self
    {
        return new self('Webhook payload is missing an event type.');
    }

    public static function malformedPayload(): self
    {
        return new self('Webhook payload is not valid JSON or is missing required structure.');
    }

    public static function unknownEventType(string $type): self
    {
        return new self("Unsupported webhook event type '{$type}'.");
    }
}
