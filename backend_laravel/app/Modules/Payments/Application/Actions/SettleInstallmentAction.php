<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Application\DTOs\SettleInstallmentData;
use App\Modules\Payments\Application\Services\OrderPaymentSyncService;
use App\Modules\Payments\Application\Services\PaymentStateTransitionService;
use App\Modules\Payments\Domain\Exceptions\InstallmentException;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Models\Installment;
use App\Modules\Payments\Infrastructure\Persistence\Models\InstallmentPlan;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * SettleInstallmentAction — marks one scheduled installment as paid.
 *
 * Rules (PHP mirrors of the DB boundary):
 *  - installment must exist and not already be settled
 *  - sequential settlement: installment N may only settle after 1..N-1 are
 *    settled (waived counts as settled) — enforced here, mirrored by the
 *    gateway's own schedule
 *  - UNIQUE(installments.gateway_transaction_id): replaying the same gateway
 *    charge is idempotent (returns the already-paid installment)
 *  - a settled 'charge' payment_transaction is recorded per installment
 *  - payment status advances: first settlement → partially_paid; all
 *    settled → paid; plan completes when every installment is settled
 */
class SettleInstallmentAction
{
    public function __construct(
        private readonly PaymentStateTransitionService $stateMachine,
        private readonly SettlePaymentTransactionAction $settleTransaction,
        private readonly OrderPaymentSyncService $orderSync,
    ) {
    }

    public function execute(SettleInstallmentData $dto): Installment
    {
        return DB::transaction(function () use ($dto): Installment {
            /** @var Installment|null $installment */
            $installment = Installment::where('id', $dto->installmentId)
                ->lockForUpdate()
                ->first();

            if ($installment === null) {
                throw InstallmentException::notFound();
            }

            // Idempotent replay of the same gateway charge.
            if ($installment->isSettled()) {
                if ($installment->gateway_transaction_id === $dto->gatewayTransactionId) {
                    return $installment;
                }

                throw InstallmentException::alreadySettled((int) $installment->installment_number);
            }

            /** @var InstallmentPlan $plan */
            $plan = $installment->plan()->lockForUpdate()->firstOrFail();

            // Sequential settlement: every earlier installment must be settled.
            $unsettledEarlier = $plan->installments()
                ->where('installment_number', '<', (int) $installment->installment_number)
                ->whereNotIn('status', [Installment::STATUS_PAID, Installment::STATUS_WAIVED])
                ->exists();

            if ($unsettledEarlier) {
                throw InstallmentException::outOfSequence((int) $installment->installment_number);
            }

            try {
                $installment->status = Installment::STATUS_PAID;
                $installment->paid_at = $dto->settledAt !== null ? new \DateTimeImmutable($dto->settledAt) : now();
                $installment->gateway_transaction_id = $dto->gatewayTransactionId;
                $installment->save();
            } catch (QueryException $e) {
                // UNIQUE gateway_transaction_id → duplicate settlement.
                if ((string) $e->getCode() === '23505') {
                    throw InstallmentException::alreadySettled((int) $installment->installment_number);
                }

                throw $e;
            }

            /** @var \App\Modules\Payments\Infrastructure\Persistence\Models\Payment $payment */
            $payment = $plan->payment()->lockForUpdate()->firstOrFail();

            $this->settleTransaction->execute(new \App\Modules\Payments\Application\DTOs\SettleTransactionData(
                paymentId: (int) $payment->id,
                attemptId: $payment->latestAttempt()?->id,
                transactionType: PaymentTransaction::TYPE_CHARGE,
                gatewayTransactionId: $dto->gatewayTransactionId,
                amount: (string) $installment->amount,
                currency: (string) $payment->currency,
                metadata: $dto->metadata + ['installment_number' => (int) $installment->installment_number],
            ));

            // Advance the payment + plan based on remaining installments.
            $remaining = $plan->installments()
                ->whereNotIn('status', [Installment::STATUS_PAID, Installment::STATUS_WAIVED])
                ->count();

            if ($remaining === 0) {
                $plan->status = InstallmentPlan::STATUS_COMPLETED;
                $plan->save();

                // pending/processing → partially_paid → paid (two legal hops).
                if (in_array((string) $payment->status, [PaymentStatus::PENDING, PaymentStatus::PROCESSING, PaymentStatus::AUTHORIZED], true)) {
                    $this->stateMachine->transition($payment, PaymentStatus::PARTIALLY_PAID);
                }

                $payment = $this->stateMachine->transition($payment, PaymentStatus::PAID);

                // Fully settled plan == captured payment: sync the order.
                $order = $payment->order;

                if ($order !== null) {
                    $this->orderSync->markPaid($order, null);
                }
            } else {
                if (in_array((string) $payment->status, [PaymentStatus::PENDING, PaymentStatus::PROCESSING, PaymentStatus::AUTHORIZED], true)) {
                    $this->stateMachine->transition($payment, PaymentStatus::PARTIALLY_PAID);
                }
            }

            return $installment;
        });
    }
}
