<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

/**
 * Payment status constants and the legal-transition adjacency map.
 *
 * This map MIRRORS the authoritative PostgreSQL function
 * fn_validate_payment_status_transition()
 * (database/sql/018_functions_triggers.sql §8). The DB trigger
 * trg_payments_status_transition is the source of truth and rejects any
 * transition not listed here with SQLSTATE P0005; the PHP map exists only
 * to produce friendly 422 responses before the write is attempted and to
 * translate P0005 into a domain exception.
 */
final class PaymentStatus
{
    public const PENDING             = 'pending';
    public const PROCESSING          = 'processing';
    public const AUTHORIZED          = 'authorized';
    public const PAID                = 'paid';
    public const PARTIALLY_PAID      = 'partially_paid';
    public const FAILED              = 'failed';
    public const CANCELLED           = 'cancelled';
    public const REFUND_PENDING      = 'refund_pending';
    public const PARTIALLY_REFUNDED  = 'partially_refunded';
    public const REFUNDED            = 'refunded';

    /**
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::PENDING            => [self::PROCESSING, self::CANCELLED, self::FAILED],
            self::PROCESSING         => [self::AUTHORIZED, self::PAID, self::PARTIALLY_PAID, self::FAILED, self::CANCELLED],
            self::AUTHORIZED         => [self::PAID, self::CANCELLED],
            self::PAID               => [self::REFUND_PENDING],
            self::PARTIALLY_PAID     => [self::PAID, self::REFUND_PENDING, self::FAILED],
            self::FAILED             => [self::PROCESSING, self::CANCELLED],
            self::CANCELLED          => [],
            self::REFUND_PENDING     => [self::PARTIALLY_REFUNDED, self::REFUNDED],
            self::PARTIALLY_REFUNDED => [self::REFUNDED],
            self::REFUNDED           => [],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::transitions());
    }

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::transitions()[$from] ?? [], true);
    }

    /**
     * Statuses from which a refund may be initiated (payment has settled value).
     *
     * @return list<string>
     */
    public static function refundableStatuses(): array
    {
        return [self::PAID, self::PARTIALLY_PAID];
    }

    public static function isRefundable(string $status): bool
    {
        return in_array($status, self::refundableStatuses(), true);
    }

    /**
     * Statuses that represent a settled (successful) payment outcome.
     *
     * @return list<string>
     */
    public static function settledStatuses(): array
    {
        return [self::AUTHORIZED, self::PAID, self::PARTIALLY_PAID];
    }
}
