<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

/**
 * Payment method constants (payments.payment_method CHECK constraint).
 *
 * 'full'        — single charge via the gateway.
 * 'installment' — spread via Tabby (4x) or Tamara (3x/6x) installment plan.
 */
final class PaymentMethod
{
    public const FULL        = 'full';
    public const INSTALLMENT = 'installment';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::FULL, self::INSTALLMENT];
    }

    public static function isKnown(string $method): bool
    {
        return in_array($method, self::all(), true);
    }
}
