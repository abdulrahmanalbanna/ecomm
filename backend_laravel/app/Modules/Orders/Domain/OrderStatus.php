<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain;

/**
 * Order status constants and the legal-transition adjacency map.
 *
 * This map MIRRORS the authoritative PostgreSQL function
 * fn_validate_order_status_transition() (database/sql/018_functions_triggers.sql).
 * The DB trigger trg_orders_status_transition is the source of truth and will
 * reject any transition not listed here with SQLSTATE P0001; the PHP map exists
 * only to produce friendly 422 responses before the write is attempted.
 */
final class OrderStatus
{
    public const PENDING          = 'pending';
    public const PAYMENT_PENDING  = 'payment_pending';
    public const CONFIRMED        = 'confirmed';
    public const PROCESSING       = 'processing';
    public const SHIPPED          = 'shipped';
    public const DELIVERED        = 'delivered';
    public const CANCELLED        = 'cancelled';
    public const REFUND_REQUESTED = 'refund_requested';
    public const REFUND_REJECTED  = 'refund_rejected';
    public const REFUNDED         = 'refunded';
    public const FAILED           = 'failed';

    /**
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::PENDING          => [self::PAYMENT_PENDING, self::CANCELLED, self::FAILED],
            self::PAYMENT_PENDING  => [self::CONFIRMED, self::FAILED, self::CANCELLED],
            self::CONFIRMED        => [self::PROCESSING, self::CANCELLED],
            self::PROCESSING       => [self::SHIPPED, self::CANCELLED],
            self::SHIPPED          => [self::DELIVERED],
            self::DELIVERED        => [self::REFUND_REQUESTED],
            self::REFUND_REQUESTED => [self::REFUNDED, self::REFUND_REJECTED],
            self::REFUND_REJECTED  => [],
            self::CANCELLED        => [],
            self::REFUNDED         => [],
            self::FAILED           => [],
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
     * Statuses from which the DB state machine permits → cancelled.
     *
     * @return list<string>
     */
    public static function cancellableStatuses(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (string $s): bool => in_array(self::CANCELLED, self::transitions()[$s], true)
        ));
    }

    /**
     * Statuses a customer may self-cancel from (business rule: before the
     * order enters fulfillment). Admin cancellation uses cancellableStatuses().
     *
     * @return list<string>
     */
    public static function customerCancellableStatuses(): array
    {
        return [self::PENDING, self::PAYMENT_PENDING];
    }
}
