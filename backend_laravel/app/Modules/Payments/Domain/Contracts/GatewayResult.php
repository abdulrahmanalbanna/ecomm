<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Contracts;

/**
 * Immutable, sanitized result of a gateway operation.
 *
 * Gateway HTTP payloads are normalized into this value object so the
 * application layer never parses raw gateway JSON. `raw` is stored in
 * gateway_response JSONB columns AFTER redaction of credential-like keys
 * (see PaymentGateway::sanitizeGatewayResponse()).
 */
final class GatewayResult
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED  = 'failed';

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $transactionId = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
        public readonly array $raw = [],
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
