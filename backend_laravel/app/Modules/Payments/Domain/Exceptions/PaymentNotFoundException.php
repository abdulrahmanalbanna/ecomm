<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

/**
 * Payment not found — also used for payments the requester may not see
 * (no existence leakage across customers). → HTTP 404.
 */
final class PaymentNotFoundException extends PaymentDomainException
{
    public static function forPublicId(string $publicId): self
    {
        return new self("Payment '{$publicId}' not found.");
    }

    public static function forOrder(string $orderPublicId): self
    {
        return new self("No payment exists for order '{$orderPublicId}'.");
    }
}
