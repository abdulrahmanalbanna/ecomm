<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Services;

use App\Modules\Payments\Domain\Exceptions\InvalidPaymentStateException;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * PaymentStateTransitionService — the ONLY place a payment's status column
 * is written.
 *
 * Mirrors the Orders module's TransitionOrderStatusAction pattern:
 *  1. Re-read the row with a FOR UPDATE lock so concurrent transitions
 *     serialize (webhook + retry + reconciliation cannot interleave).
 *  2. Validate against the PHP adjacency map (PaymentStatus) for friendly 422s.
 *  3. Write. The PostgreSQL trigger trg_payments_status_transition remains
 *     authoritative: any drift between PHP and SQL surfaces as SQLSTATE
 *     P0005 and is translated back into InvalidPaymentStateException.
 *
 * Idempotent no-op: transitioning to the status the payment already holds
 * returns the payment untouched (webhook re-delivery safety).
 */
final class PaymentStateTransitionService
{
    /**
     * Apply a status transition inside the caller's transaction (or its own).
     *
     * @param callable(Payment): void|null $sideEffects runs while the row is
     *        locked, BEFORE the status write, so failures roll everything back.
     */
    public function transition(Payment $payment, string $toStatus, ?callable $sideEffects = null): Payment
    {
        if (! PaymentStatus::isKnown($toStatus)) {
            throw new InvalidPaymentStateException("Unknown payment status '{$toStatus}'.");
        }

        return DB::transaction(function () use ($payment, $toStatus, $sideEffects): Payment {
            /** @var Payment $locked */
            $locked = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            $from = (string) $locked->status;

            // Re-delivery of the same terminal state is a no-op.
            if ($from === $toStatus) {
                return $locked;
            }

            if (! PaymentStatus::canTransition($from, $toStatus)) {
                throw InvalidPaymentStateException::between($from, $toStatus);
            }

            if ($sideEffects !== null) {
                $sideEffects($locked);
            }

            try {
                $locked->status = $toStatus;
                $locked->save();
            } catch (QueryException $e) {
                if ((string) $e->getCode() === 'P0005' || str_contains($e->getMessage(), 'Invalid payment status transition')) {
                    throw InvalidPaymentStateException::between($from, $toStatus);
                }

                throw $e;
            }

            return $locked;
        });
    }

    /**
     * Non-locking pre-flight check used by Actions to fail fast with a clean
     * 422 before touching a gateway.
     */
    public function assertCanTransition(Payment $payment, string $toStatus): void
    {
        $from = (string) $payment->status;

        if ($from !== $toStatus && ! PaymentStatus::canTransition($from, $toStatus)) {
            throw InvalidPaymentStateException::between($from, $toStatus);
        }
    }
}
