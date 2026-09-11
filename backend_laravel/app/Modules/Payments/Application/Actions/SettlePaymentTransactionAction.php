<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Application\DTOs\SettleTransactionData;
use App\Modules\Payments\Application\Services\PaymentAmountService;
use App\Modules\Payments\Application\Services\PaymentIdempotencyService;
use App\Modules\Payments\Domain\Exceptions\PaymentNotFoundException;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentAttempt;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentTransaction;

/**
 * SettlePaymentTransactionAction — records a SETTLED financial movement
 * against a payment intent (charge / refund / chargeback / adjustment).
 *
 * Idempotency: payment_transactions.gateway_transaction_id is UNIQUE. A
 * duplicate settlement (webhook re-delivery, double replay) returns null —
 * the caller treats it as "already recorded", never as an error.
 *
 * When the settlement is a successful charge linked to an attempt, that
 * attempt is finalized as 'success'.
 */
class SettlePaymentTransactionAction
{
    public function __construct(
        private readonly PaymentIdempotencyService $idempotency,
        private readonly PaymentAmountService $amounts,
    ) {
    }

    /**
     * @return PaymentTransaction|null null when the gateway transaction was already recorded
     *
     * @throws PaymentNotFoundException
     */
    public function execute(SettleTransactionData $dto): ?PaymentTransaction
    {
        if (! in_array($dto->transactionType, PaymentTransaction::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown transaction type '{$dto->transactionType}'.");
        }

        if (! $this->amounts->isPositive($dto->amount)) {
            throw new \InvalidArgumentException('Settled transaction amount must be positive.');
        }

        /** @var Payment|null $payment */
        $payment = Payment::find($dto->paymentId);

        if ($payment === null) {
            throw PaymentNotFoundException::forPublicId((string) $dto->paymentId);
        }

        $transaction = $this->idempotency->insertTransactionIfAbsent([
            'payment_id' => (int) $payment->id,
            'attempt_id' => $dto->attemptId,
            'transaction_type' => $dto->transactionType,
            'gateway_transaction_id' => $dto->gatewayTransactionId,
            'amount' => $this->amounts->normalize($dto->amount),
            'currency' => $dto->currency,
            'settled_at' => $dto->settledAt !== null
                ? \DateTimeImmutable::createFromFormat('U', (string) strtotime($dto->settledAt))
                : now(),
            'metadata' => $dto->metadata,
        ]);

        if ($transaction === null) {
            return null; // duplicate — already settled
        }

        if ($dto->transactionType === PaymentTransaction::TYPE_CHARGE && $dto->attemptId !== null) {
            /** @var PaymentAttempt|null $attempt */
            $attempt = PaymentAttempt::find($dto->attemptId);

            if ($attempt !== null && $attempt->status === PaymentAttempt::STATUS_PENDING) {
                $attempt->status = PaymentAttempt::STATUS_SUCCESS;
                $attempt->gateway_transaction_id = $attempt->gateway_transaction_id ?? $dto->gatewayTransactionId;
                $attempt->completed_at = now();
                $attempt->save();
            }
        }

        return $transaction;
    }
}
