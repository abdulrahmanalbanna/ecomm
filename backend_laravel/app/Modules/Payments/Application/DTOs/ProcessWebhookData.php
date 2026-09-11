<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\DTOs;

/**
 * Immutable input for ProcessPaymentWebhookAction.
 *
 * The raw body is preserved verbatim for signature verification and audit;
 * the decoded payload is provided for convenience.
 */
final readonly class ProcessWebhookData
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $gatewayCode,
        public string $rawBody,
        public array $payload,
        public ?string $signatureHeader,
    ) {
    }
}
