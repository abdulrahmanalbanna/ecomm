<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Application\Actions\Concerns\FindsPayments;
use App\Modules\Payments\Application\DTOs\CreateRefundData;
use App\Modules\Payments\Application\DTOs\SettleTransactionData;
use App\Modules\Payments\Application\Services\OrderPaymentSyncService;
use App\Modules\Payments\Application\Services\PaymentGatewayResolver;
use App\Modules\Payments\Application\Services\PaymentStateTransitionService;
use App\Modules\Payments\Application\Services\RefundBalanceService;
use App\Modules\Payments\Domain\Exceptions\InvalidRefundException;
use App\Modules\Payments\Domain\Exceptions\RefundProcessingException;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentTransaction;
use App\Modules\Payments\Infrastructure\Persistence\Models\Refund;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * CreateRefundAction — admin initiates a partial or full refund.
 *
 * Concurrency design (the important part):
 *   1. SHORT TXN A — lock the payment, validate eligibility + balance, and
 *      INSERT the refund row with status 'pending'. The DB trigger
 *      trg_refunds_validate_total SELECTs the payment FOR UPDATE and caps
 *      SUM(active refunds) at payments.amount, so two simultaneous refund
 *      requests serialize here — the second gets 23514 → 422. The pending
 *      row RESERVES the balance before any external call.
 *   2. GATEWAY HTTP — submit the refund (no lock held).
 *   3. SHORT TXN B — record the outcome:
 *        rejected → refund 'failed' (the reservation is released because the
 *                   trigger only counts pending/processing/processed; the
 *                   payment stays 'paid' and remains refundable/retryable)
 *        accepted → payment paid/partially_paid → refund_pending, refund
 *                   'processing' with the gateway refund id. Settlement is
 *                   confirmed asynchronously by ProcessRefundWebhookAction.
 *        synchronous success → also settle the refund transaction and move
 *                   the payment to partially_refunded / refunded.
 */
class CreateRefundAction
{
    use FindsPayments;

    public function __construct(
        private readonly RefundBalanceService $balances,
        private readonly PaymentGatewayResolver $gatewayResolver,
        private readonly PaymentStateTransitionService $stateMachine,
        private readonly SettlePaymentTransactionAction $settleTransaction,
        private readonly OrderPaymentSyncService $orderSync,
    ) {
    }

    public function execute(CreateRefundData $dto): Refund
    {
        $payment = $this->findForAdmin($dto->paymentPublicId);

        // ---- SHORT TXN A: validate + reserve --------------------------------
        $refund = DB::transaction(function () use ($payment, $dto): Refund {
            /** @var Payment $locked */
            $locked = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            $this->balances->assertRefundable($locked);

            $amount = $this->balances->assertAmountWithinBalance($locked, $dto->amount);

            $charge = $this->balances->settledCharge($locked);

            if ($charge === null) {
                throw InvalidRefundException::noSettledCharge();
            }

            try {
                /** @var Refund $created */
                $created = Refund::create([
                    'payment_id' => (int) $locked->id,
                    'transaction_id' => (int) $charge->id,
                    'amount' => $amount,
                    'reason' => $dto->reason,
                    'status' => Refund::STATUS_PENDING,
                    'initiated_by' => $dto->initiatedBy,
                ]);
            } catch (QueryException $e) {
                // trg_refunds_validate_total rejects the aggregate overflow.
                if ((string) $e->getCode() === '23514' || str_contains($e->getMessage(), 'Refund amount exceeds')) {
                    throw \App\Modules\Payments\Domain\Exceptions\RefundExceedsPaymentException::forAmounts(
                        $amount,
                        $this->balances->remainingBalance($locked),
                    );
                }

                throw $e;
            }

            return $created;
        });

        // ---- GATEWAY HTTP ----------------------------------------------------
        $payment->loadMissing('gateway');

        $processor = $this->gatewayResolver->resolveProcessor((string) $payment->gateway->code);

        $result = $processor->refund($refund, $payment);

        // ---- SHORT TXN B: record outcome -------------------------------------
        if ($result->isFailed()) {
            // Release the reservation; the payment stays refundable.
            $refund->status = Refund::STATUS_FAILED;
            $refund->save();

            throw RefundProcessingException::gatewayRejected($result->failureMessage ?? $result->failureCode);
        }

        return DB::transaction(function () use ($payment, $refund, $result, $dto): Refund {
            $locked = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            $refund->gateway_refund_id = $result->transactionId ?? ('repl_' . (string) $refund->id);
            $refund->status = Refund::STATUS_PROCESSING;
            $refund->save();

            // paid/partially_paid → refund_pending (legal per the trigger map).
            $this->stateMachine->transition($locked, PaymentStatus::REFUND_PENDING);

            if ($result->isSuccess()) {
                // Synchronous settlement (rare): record it and finish the job.
                $this->settleTransaction->execute(new SettleTransactionData(
                    paymentId: (int) $locked->id,
                    attemptId: null,
                    transactionType: PaymentTransaction::TYPE_REFUND,
                    gatewayTransactionId: (string) $refund->gateway_refund_id,
                    amount: (string) $refund->amount,
                    currency: (string) $locked->currency,
                    metadata: ['source' => 'refund_action', 'refund_id' => (int) $refund->id],
                ));

                $refund->status = Refund::STATUS_PROCESSED;
                $refund->processed_at = now();
                $refund->save();

                $remaining = $this->balances->remainingBalance($locked->fresh());

                $this->stateMachine->transition(
                    $locked,
                    bccomp($remaining, '0.00', 2) === 0 ? PaymentStatus::REFUNDED : PaymentStatus::PARTIALLY_REFUNDED,
                );
            }

            return $refund->fresh(['payment', 'transaction']);
        });
    }
}
