<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Services;

use App\Modules\Payments\Domain\Exceptions\InvalidRefundException;
use App\Modules\Payments\Domain\Exceptions\RefundExceedsPaymentException;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentTransaction;
use App\Modules\Payments\Infrastructure\Persistence\Models\Refund;

/**
 * RefundBalanceService — reusable refund eligibility + balance math.
 *
 * The friendly checks here (status refundable, amount ≤ remaining balance,
 * a settled charge exists to reverse) produce clean 422s, but the AUTHORITATIVE
 * cap is the PostgreSQL trigger trg_refunds_validate_total, which SELECTs the
 * payment FOR UPDATE and rejects any INSERT/UPDATE whose active-refund sum
 * would exceed payments.amount. Concurrent refund requests therefore serialize
 * at the DB level regardless of this service.
 */
final class RefundBalanceService
{
    public function __construct(
        private readonly PaymentAmountService $amounts,
    ) {
    }

    /**
     * Assert the payment can currently be refunded.
     *
     * @throws InvalidRefundException
     */
    public function assertRefundable(Payment $payment): void
    {
        if (! PaymentStatus::isRefundable((string) $payment->status)) {
            throw InvalidRefundException::notRefundable((string) $payment->status);
        }
    }

    /**
     * The settled 'charge' transaction a refund should reference (largest
     * charge when multiple exist). Null when nothing has settled yet.
     */
    public function settledCharge(Payment $payment): ?PaymentTransaction
    {
        /** @var PaymentTransaction|null $txn */
        $txn = $payment->transactions()
            ->where('transaction_type', PaymentTransaction::TYPE_CHARGE)
            ->whereNotNull('settled_at')
            ->orderByDesc('amount')
            ->first();

        return $txn;
    }

    /**
     * Validate a requested refund amount against the remaining balance.
     *
     * @throws InvalidRefundException     non-numeric / non-positive amount
     * @throws RefundExceedsPaymentException amount > remaining balance
     */
    public function assertAmountWithinBalance(Payment $payment, string $requested): string
    {
        if (! is_numeric($requested)) {
            throw InvalidRefundException::invalidAmount($requested);
        }

        $amount = $this->amounts->normalize($requested);

        if (! $this->amounts->isPositive($amount)) {
            throw InvalidRefundException::invalidAmount($requested);
        }

        $remaining = $this->remainingBalance($payment);

        if (! $this->amounts->gte($remaining, $amount)) {
            throw RefundExceedsPaymentException::forAmounts($amount, $remaining);
        }

        return $amount;
    }

    /**
     * amount - SUM(active refunds).
     */
    public function remainingBalance(Payment $payment): string
    {
        return $this->amounts->remainingRefundable($payment);
    }

    /**
     * Classify a validated refund amount against the remaining balance.
     */
    public function isFullRefund(Payment $payment, string $amount): bool
    {
        return bccomp(
            $this->amounts->normalize($amount),
            $this->remainingBalance($payment),
            PaymentAmountService::SCALE,
        ) === 0;
    }

    /**
     * Total refunded so far (active statuses only).
     */
    public function refundedTotal(Payment $payment): string
    {
        return $this->amounts->subtract((string) $payment->amount, $this->remainingBalance($payment));
    }
}
