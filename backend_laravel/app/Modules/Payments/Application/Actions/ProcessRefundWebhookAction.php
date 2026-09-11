<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Application\Actions\Concerns\MatchesWebhookPayload;
use App\Modules\Payments\Application\DTOs\ProcessRefundWebhookData;
use App\Modules\Payments\Application\DTOs\SettleTransactionData;
use App\Modules\Payments\Application\Services\PaymentAmountService;
use App\Modules\Payments\Application\Services\PaymentStateTransitionService;
use App\Modules\Payments\Domain\Exceptions\PaymentNotFoundException;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentTransaction;
use App\Modules\Payments\Infrastructure\Persistence\Models\Refund;

/**
 * ProcessRefundWebhookAction — applies a VERIFIED refund lifecycle event.
 *
 * Invoked by ProcessPaymentWebhookAction after the raw event has been
 * persisted (signature verified, deduped). This action owns the refund
 * settlement semantics:
 *
 *  - refund failed/rejected → refund row marked 'failed'. The payment stays
 *    'refund_pending' (the trigger forbids refund_pending → paid); a later
 *    successful refund resolves it.
 *  - refund processed       → refund row 'processed', a settled 'refund'
 *    payment_transaction is recorded (idempotent via UNIQUE
 *    gateway_transaction_id), and the payment moves to
 *    partially_refunded / refunded depending on the remaining balance.
 *
 * Re-delivery safety: refund rows are keyed by gateway_refund_id (UNIQUE) and
 * transaction settlement is ON CONFLICT — replaying the event is a no-op.
 */
class ProcessRefundWebhookAction
{
    use MatchesWebhookPayload;

    public function __construct(
        private readonly PaymentStateTransitionService $stateMachine,
        private readonly SettlePaymentTransactionAction $settleTransaction,
        private readonly PaymentAmountService $amounts,
    ) {
    }

    /**
     * @return array{effect: string, payment_public_id: string}
     */
    public function execute(ProcessRefundWebhookData $dto): array
    {
        $payment = $this->matchPayment($dto->payload);

        if ($payment === null) {
            throw PaymentNotFoundException::forPublicId('(unmatched refund webhook reference)');
        }

        $refundId = $this->extractString($dto->payload, [
            'refund.id', 'refund_id', 'refundId', 'id',
        ]);

        /** @var Refund|null $refund */
        $refund = $refundId !== null
            ? $payment->refunds()->where('gateway_refund_id', $refundId)->first()
            : null;

        if ($refund === null) {
            throw PaymentNotFoundException::forPublicId((string) $payment->public_id);
        }

        $normalized = strtolower($dto->eventType);
        $failed = str_contains($normalized, 'fail') || str_contains($normalized, 'reject');

        if ($failed) {
            if ($refund->status !== Refund::STATUS_FAILED) {
                $refund->status = Refund::STATUS_FAILED;
                $refund->save();
            }

            return ['effect' => 'refund_failed', 'payment_public_id' => (string) $payment->public_id];
        }

        // Already processed → idempotent no-op.
        if ($refund->status === Refund::STATUS_PROCESSED) {
            return ['effect' => 'already_processed', 'payment_public_id' => (string) $payment->public_id];
        }

        $refund->status = Refund::STATUS_PROCESSED;
        $refund->processed_at = now();
        $refund->save();

        $this->settleTransaction->execute(new SettleTransactionData(
            paymentId: (int) $payment->id,
            attemptId: null,
            transactionType: PaymentTransaction::TYPE_REFUND,
            gatewayTransactionId: (string) ($refundId ?? ('refund_' . (string) $refund->id)),
            amount: (string) $refund->amount,
            currency: (string) $payment->currency,
            metadata: ['source' => 'webhook', 'refund_id' => (int) $refund->id],
        ));

        // Recompute the balance across ALL active refunds.
        $remaining = $this->amounts->remainingRefundable($payment->fresh());

        $target = bccomp($remaining, '0.00', PaymentAmountService::SCALE) === 0
            ? PaymentStatus::REFUNDED
            : PaymentStatus::PARTIALLY_REFUNDED;

        $payment = $this->stateMachine->transition($payment, $target);

        return ['effect' => 'refund_processed', 'payment_public_id' => (string) $payment->public_id];
    }
}
