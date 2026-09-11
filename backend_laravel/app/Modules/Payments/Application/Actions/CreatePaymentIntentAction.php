<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Orders\Domain\Exceptions\OrderNotFoundException;
use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use App\Modules\Payments\Application\DTOs\CreatePaymentIntentData;
use App\Modules\Payments\Application\Services\OrderPaymentSyncService;
use App\Modules\Payments\Application\Services\PaymentAmountService;
use App\Modules\Payments\Application\Services\PaymentGatewayResolver;
use App\Modules\Payments\Application\Services\PaymentIdempotencyService;
use App\Modules\Payments\Application\Services\PaymentStateTransitionService;
use App\Modules\Payments\Domain\Exceptions\DuplicatePaymentException;
use App\Modules\Payments\Domain\Exceptions\PaymentAlreadyExistsException;
use App\Modules\Payments\Domain\Exceptions\PaymentProcessingException;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * CreatePaymentIntentAction — the concrete use case "customer starts paying".
 *
 * Transaction discipline (§ external calls must never hold a DB transaction):
 *   1. SHORT TXN A — persist the intent (payments) + attempt #1
 *   2. GATEWAY HTTP — create the checkout session (no lock held)
 *   3. SHORT TXN B — record the gateway reference, advance statuses
 *
 * Guarantees:
 *  - exactly one intent per order (payments.order_id UNIQUE; a second request
 *    returns 409 unless the same idempotency key replays the original)
 *  - the amount is derived from orders.total_amount, never from the client
 *  - a gateway transport failure leaves the intent retryable (pending), and
 *    the attempt row records the failure
 *  - the order advances pending → payment_pending via Orders' own action
 */
class CreatePaymentIntentAction
{
    public function __construct(
        private readonly PaymentGatewayResolver $gatewayResolver,
        private readonly PaymentAmountService $amounts,
        private readonly PaymentIdempotencyService $idempotency,
        private readonly PaymentStateTransitionService $stateMachine,
        private readonly OrderPaymentSyncService $orderSync,
    ) {
    }

    public function execute(CreatePaymentIntentData $dto): Payment
    {
        /** @var Order|null $order */
        $order = Order::forUser($dto->userId)->where('public_id', $dto->orderPublicId)->first();

        if ($order === null) {
            throw OrderNotFoundException::forPublicId($dto->orderPublicId);
        }

        // Idempotent replay: same key + same order returns the original intent.
        $idempotencyKey = $dto->idempotencyKey ?? $this->idempotency->generateKey();

        $replay = $this->idempotency->resolvePaymentReplay($idempotencyKey, (int) $order->id);

        if ($replay !== null) {
            return $replay->load(['gateway', 'attempts', 'installmentPlan']);
        }

        // Validate the gateway (row + processor) BEFORE writing anything.
        $amount = $this->amounts->payableAmount($order);

        ['row' => $gatewayRow, 'processor' => $processor] = $this->gatewayResolver->resolveForPayment(
            code: $dto->gatewayCode,
            paymentMethod: $dto->paymentMethod,
            numberOfInstallments: $dto->numberOfInstallments,
            currency: (string) $order->currency,
        );

        // ---- SHORT TXN A: persist intent + attempt #1 -----------------------
        try {
            /** @var array{payment: Payment, attempt: PaymentAttempt} $created */
            $created = DB::transaction(function () use ($order, $gatewayRow, $dto, $amount, $idempotencyKey): array {
                /** @var Payment $payment */
                $payment = Payment::create([
                    'order_id'        => $order->id,
                    'gateway_id'      => $gatewayRow->id,
                    'payment_method'  => $dto->paymentMethod,
                    'amount'          => $amount,
                    'currency'        => (string) $order->currency,
                    'status'          => PaymentStatus::PENDING,
                    'idempotency_key' => $idempotencyKey,
                ]);

                /** @var PaymentAttempt $attempt */
                $attempt = PaymentAttempt::create([
                    'payment_id'     => $payment->id,
                    'attempt_number' => 1,
                    'amount'         => $amount,
                    'currency'       => (string) $order->currency,
                    'status'         => PaymentAttempt::STATUS_PENDING,
                ]);

                return ['payment' => $payment, 'attempt' => $attempt];
            });
        } catch (QueryException $e) {
            // payments.order_id UNIQUE → the order already has an intent.
            if ((string) $e->getCode() === '23505') {
                if ($this->idempotency->findPaymentByKey($idempotencyKey) !== null) {
                    throw DuplicatePaymentException::keyConflict($idempotencyKey);
                }

                throw PaymentAlreadyExistsException::forOrder($dto->orderPublicId);
            }

            throw $e;
        }

        $payment = $created['payment'];
        $attempt = $created['attempt'];

        // ---- GATEWAY HTTP (outside any transaction) -------------------------
        $result = $processor->createCheckout($payment, $dto->userId, $dto->numberOfInstallments);

        // ---- Record outcome -------------------------------------------------
        // A gateway failure is persisted OUTSIDE a transaction so the audit
        // trail survives the exception that follows (the intent stays
        // 'pending' and remains retryable).
        if ($result->isFailed()) {
            $attempt->status = PaymentAttempt::STATUS_FAILED;
            $attempt->failure_code = $result->failureCode ?? 'gateway_error';
            $attempt->failure_message = $result->failureMessage;
            $attempt->gateway_response = $result->raw;
            $attempt->completed_at = now();
            $attempt->save();

            $payment->gateway_response = $result->raw;
            $payment->save();

            throw PaymentProcessingException::declined($result->failureCode, $result->failureMessage);
        }

        // ---- SHORT TXN B: success/pending path ------------------------------
        return DB::transaction(function () use ($payment, $attempt, $result, $order, $dto): Payment {
            $payment->gateway_payment_id = $result->transactionId ?? $payment->gateway_payment_id;
            $payment->gateway_response = $result->raw;
            $payment->save();

            $attempt->gateway_transaction_id = $result->transactionId;
            $attempt->gateway_response = $result->raw;
            $attempt->save();

            // pending → processing: the gateway session is now live.
            $payment = $this->stateMachine->transition($payment, PaymentStatus::PROCESSING);

            // The order enters the payment window (pending → payment_pending).
            $this->orderSync->markPaymentPending($order, $dto->userId);

            // Some gateways settle synchronously (rare for BNPL, but supported):
            // processing → paid is a legal transition.
            if ($result->isSuccess()) {
                $payment = $this->stateMachine->transition($payment, PaymentStatus::PAID);

                $this->orderSync->markPaid($order, $dto->userId);
            }

            return $payment->fresh(['gateway', 'attempts', 'installmentPlan', 'order']);
        });
    }
}
