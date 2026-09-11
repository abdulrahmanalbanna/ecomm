<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Application\Actions\Concerns\FindsPayments;
use App\Modules\Payments\Application\DTOs\RetryPaymentData;
use App\Modules\Payments\Application\Services\OrderPaymentSyncService;
use App\Modules\Payments\Application\Services\PaymentGatewayResolver;
use App\Modules\Payments\Application\Services\PaymentStateTransitionService;
use App\Modules\Payments\Domain\Exceptions\InvalidPaymentStateException;
use App\Modules\Payments\Domain\Exceptions\PaymentProcessingException;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;

/**
 * RetryPaymentAction — customer retries a failed/pending payment.
 *
 * Creates payment_attempts row N+1 (UNIQUE(payment_id, attempt_number)
 * serializes concurrent retries — the loser gets 23505 → 409) and re-opens
 * the gateway session. Legal payment transitions:
 *   pending → processing   (first attempt failed before advancing)
 *   failed  → processing   (declined/expired, customer tries again)
 *
 * A retry on a settled (paid/authorized/partially_paid) or terminal
 * (cancelled/refunded) intent is rejected with 422 before any gateway call.
 */
class RetryPaymentAction
{
    use FindsPayments;

    /**
     * Statuses from which a retry is allowed.
     *
     * @var list<string>
     */
    private const RETRYABLE = [PaymentStatus::PENDING, PaymentStatus::FAILED];

    public function __construct(
        private readonly PaymentGatewayResolver $gatewayResolver,
        private readonly PaymentStateTransitionService $stateMachine,
        private readonly OrderPaymentSyncService $orderSync,
    ) {
    }

    public function execute(RetryPaymentData $dto): Payment
    {
        $payment = $this->findForUser($dto->paymentPublicId, $dto->userId);

        if (! in_array((string) $payment->status, self::RETRYABLE, true)) {
            throw InvalidPaymentStateException::notPayable((string) $payment->status);
        }

        $payment->loadMissing('gateway');

        /** @var \App\Modules\Payments\Infrastructure\Persistence\Models\PaymentGateway $gatewayRow */
        $gatewayRow = $payment->gateway;

        $processor = $this->gatewayResolver->resolveProcessor((string) $gatewayRow->code);

        // ---- SHORT TXN A: open attempt N+1 ----------------------------------
        $attempt = DB::transaction(function () use ($payment): PaymentAttempt {
            /** @var Payment $locked */
            $locked = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            $next = (int) ($locked->attempts()->max('attempt_number')) + 1;

            /** @var PaymentAttempt $attempt */
            return PaymentAttempt::create([
                'payment_id'     => $locked->id,
                'attempt_number' => $next,
                'amount'         => (string) $locked->amount,
                'currency'       => (string) $locked->currency,
                'status'         => PaymentAttempt::STATUS_PENDING,
            ]);
        });

        // ---- GATEWAY HTTP ----------------------------------------------------
        $result = ($payment->gateway_payment_id ?? '') !== ''
            ? $processor->retryCheckout($payment, $attempt)
            : $processor->createCheckout($payment, $dto->userId, $payment->installmentPlan?->number_of_installments);

        // ---- Record outcome ---------------------------------------------------
        if ($result->isFailed()) {
            $attempt->status = PaymentAttempt::STATUS_FAILED;
            $attempt->failure_code = $result->failureCode ?? 'gateway_error';
            $attempt->failure_message = $result->failureMessage;
            $attempt->gateway_response = $result->raw;
            $attempt->completed_at = now();
            $attempt->save();

            throw PaymentProcessingException::declined($result->failureCode, $result->failureMessage);
        }

        return DB::transaction(function () use ($payment, $attempt, $result, $dto): Payment {
            if ($result->transactionId !== null && $result->transactionId !== '') {
                $payment->gateway_payment_id = $result->transactionId;
            }

            $payment->gateway_response = $result->raw;
            $payment->save();

            $attempt->gateway_transaction_id = $result->transactionId;
            $attempt->gateway_response = $result->raw;
            $attempt->save();

            $payment = $this->stateMachine->transition($payment, PaymentStatus::PROCESSING);

            // Keep the order in the payment window if it never entered it.
            $order = $payment->order;

            if ($order !== null) {
                $this->orderSync->markPaymentPending($order, $dto->userId);
            }

            if ($result->isSuccess()) {
                $payment = $this->stateMachine->transition($payment, PaymentStatus::PAID);

                if ($order !== null) {
                    $this->orderSync->markPaid($order, $dto->userId);
                }
            }

            return $payment->fresh(['gateway', 'attempts', 'installmentPlan', 'order']);
        });
    }
}
