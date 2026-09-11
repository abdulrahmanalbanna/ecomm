<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Services;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use App\Modules\Payments\Domain\Exceptions\OrderNotPayableException;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use App\Modules\Payments\Infrastructure\Persistence\Models\Refund;

/**
 * PaymentAmountService — server-side money authority for the Payments module.
 *
 * Rules:
 *  - The payable amount is ALWAYS derived from orders.total_amount. Client
 *    amounts are never accepted (mirrors CheckoutAction's price authority).
 *  - All arithmetic uses bcmath at scale 2. Values are exact decimal STRINGS;
 *    floats are never introduced (mirrors the Orders module).
 *  - An order is payable only from pending or payment_pending (the order
 *    state machine's pre-payment window).
 */
final class PaymentAmountService
{
    public const SCALE = 2;

    /**
     * The exact amount due for an order, as a decimal string.
     *
     * @throws OrderNotPayableException when the order is in a non-payable status
     */
    public function payableAmount(Order $order): string
    {
        if (! in_array((string) $order->status, [OrderStatus::PENDING, OrderStatus::PAYMENT_PENDING], true)) {
            throw OrderNotPayableException::forStatus((string) $order->status);
        }

        return $this->normalize((string) $order->total_amount);
    }

    /**
     * Normalize any numeric string to scale-2 decimal (truncated toward zero,
     * matching NUMERIC(12,2) rounding on insert of already-scaled values).
     */
    public function normalize(string $amount): string
    {
        return bcadd($amount, '0', self::SCALE);
    }

    public function isPositive(string $amount): bool
    {
        return bccomp($this->normalize($amount), '0', self::SCALE) > 0;
    }

    /**
     * True when $a >= $b at scale 2.
     */
    public function gte(string $a, string $b): bool
    {
        return bccomp($this->normalize($a), $this->normalize($b), self::SCALE) >= 0;
    }

    public function subtract(string $a, string $b): string
    {
        return bcsub($this->normalize($a), $this->normalize($b), self::SCALE);
    }

    public function add(string $a, string $b): string
    {
        return bcadd($this->normalize($a), $this->normalize($b), self::SCALE);
    }

    /**
     * The payment's remaining refundable balance:
     *   amount - SUM(active refunds).
     *
     * Active = pending|processing|processed (the exact set the DB trigger
     * trg_refunds_validate_total counts). Failed refunds free capacity.
     */
    public function remainingRefundable(Payment $payment): string
    {
        // ROUND(...)::text keeps the aggregate an EXACT decimal string —
        // Eloquent's sum() would cast NUMERIC to float, which is forbidden
        // for money in this codebase.
        $refunded = (string) $payment->refunds()
            ->whereIn('status', Refund::ACTIVE_STATUSES)
            ->selectRaw('ROUND(COALESCE(SUM(amount), 0), 2)::text as agg')
            ->value('agg');

        return $this->subtract((string) $payment->amount, $refunded);
    }

    /**
     * True when the payment status represents money actually captured
     * (used by refund eligibility checks).
     */
    public function isCaptured(Payment $payment): bool
    {
        return PaymentStatus::isRefundable((string) $payment->status);
    }
}
