<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\DTOs;

/**
 * Immutable input for ProcessRefundWebhookAction — a refund lifecycle event
 * delivered by a gateway webhook (already verified + persisted by
 * ProcessPaymentWebhookAction).
 */
final readonly class ProcessRefundWebhookData
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $gatewayCode,
        public string $eventType,
        public string $gatewayEventId,
        public array $payload,
    ) {
    }
}
