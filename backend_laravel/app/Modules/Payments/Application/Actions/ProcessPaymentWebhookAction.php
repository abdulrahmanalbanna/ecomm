<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Application\DTOs\ProcessWebhookData;
use App\Modules\Payments\Application\DTOs\SettleTransactionData;
use App\Modules\Payments\Application\Services\PaymentGatewayResolver;
use App\Modules\Payments\Application\Services\PaymentIdempotencyService;
use App\Modules\Payments\Application\Services\PaymentStateTransitionService;
use App\Modules\Payments\Domain\Exceptions\InvalidWebhookException;
use App\Modules\Payments\Domain\Exceptions\PaymentNotFoundException;
use App\Modules\Payments\Domain\Exceptions\WebhookProcessingException;
use App\Modules\Payments\Application\Services\PaymentAmountService;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Models\Installment;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentTransaction;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentWebhookEvent;
use App\Modules\Payments\Infrastructure\Persistence\Models\Refund;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ProcessPaymentWebhookAction — the persist-before-process webhook pipeline.
 *
 * Order of operations (each failure mode is deliberate):
 *  1. Resolve gateway + verify HMAC signature over the RAW body.
 *     Bad signature → 400 and NOTHING is persisted (an unauthenticated
 *     caller must not be able to flood the audit table).
 *  2. Extract event id + type; malformed → 400.
 *  3. Persist the raw event with INSERT ... ON CONFLICT DO NOTHING semantics
 *     (payment_webhook_events.gateway_event_id UNIQUE). A re-delivery returns
 *     the original event marked processed — idempotent by construction.
 *  4. Process the event in a short transaction. Processing failures keep the
 *     event row with processed=false + error_message so it can be replayed;
 *     the gateway receives 202 and will retry.
 *
 * Event classification is gateway-agnostic: Tabby sends 'PAYMENT.CAPTURED',
 * 'CHECKOUT_PAYMENT.CANCELLED', 'REFUND.PROCESSED'...; Tamara sends
 * 'payment.authorized', 'refund.processed'... Both are normalized to a small
 * internal vocabulary.
 */
class ProcessPaymentWebhookAction
{
    public function __construct(
        private readonly PaymentGatewayResolver $gatewayResolver,
        private readonly PaymentIdempotencyService $idempotency,
        private readonly PaymentStateTransitionService $stateMachine,
        private readonly SettlePaymentTransactionAction $settleTransaction,
        private readonly \App\Modules\Payments\Application\Services\OrderPaymentSyncService $orderSync,
        private readonly PaymentAmountService $amounts,
    ) {
    }

    /**
     * @return array{event_id: int, duplicate: bool, processed: bool, payment_public_id: ?string, effect: string}
     *
     * @throws InvalidWebhookException   400 — signature/structure failure (nothing persisted)
     * @throws WebhookProcessingException 202 — persisted but processing failed (replayable)
     */
    public function execute(ProcessWebhookData $dto): array
    {
        $gatewayRow = $this->gatewayResolver->resolveRow($dto->gatewayCode);
        $processor = $this->gatewayResolver->resolveProcessor($dto->gatewayCode);

        if (! $processor->verifyWebhookSignature($dto->rawBody, $dto->signatureHeader)) {
            throw InvalidWebhookException::badSignature($dto->gatewayCode);
        }

        $payload = $dto->payload;

        $eventId = $this->extractString($payload, ['id', 'event_id', 'eventId', 'notification_id', 'reference_id']);

        if ($eventId === null || $eventId === '') {
            throw InvalidWebhookException::missingEventId();
        }

        $eventType = $this->extractString($payload, ['event', 'event_type', 'eventType', 'type']);

        if ($eventType === null || $eventType === '') {
            throw InvalidWebhookException::missingType();
        }

        // ---- 3. Persist raw event (idempotent) ------------------------------
        $event = $this->idempotency->insertWebhookEventIfAbsent([
            'gateway_id' => (int) $gatewayRow->id,
            'event_type' => mb_substr($eventType, 0, 100),
            'gateway_event_id' => mb_substr($eventId, 0, 200),
            'payload' => $payload,
            'processed' => false,
        ]);

        if ($event === null) {
            // Re-delivery of an already-stored event.
            /** @var PaymentWebhookEvent $existing */
            $existing = PaymentWebhookEvent::where('gateway_event_id', $eventId)->firstOrFail();

            return [
                'event_id' => (int) $existing->id,
                'duplicate' => true,
                'processed' => (bool) $existing->processed,
                'payment_public_id' => null,
                'effect' => 'ignored_duplicate',
            ];
        }

        // ---- 4. Process ------------------------------------------------------
        try {
            $effect = $this->applyEvent($gatewayRow->code, $eventType, $payload);
        } catch (PaymentNotFoundException $e) {
            $this->markFailed($event, $e->getMessage());

            throw WebhookProcessingException::forEvent($eventId, $e->getMessage());
        } catch (QueryException $e) {
            if ((string) $e->getCode() === 'P0005') {
                // Illegal transition attempted — persist for replay/debugging.
                $this->markFailed($event, $e->getMessage());

                throw WebhookProcessingException::forEvent($eventId, 'illegal payment state transition');
            }

            $this->markFailed($event, $e->getMessage());

            throw WebhookProcessingException::forEvent($eventId, 'processing failed');
        } catch (Throwable $e) {
            $this->markFailed($event, $e->getMessage());

            throw WebhookProcessingException::forEvent($eventId, 'processing failed');
        }

        $event->processed = true;
        $event->processed_at = now();
        $event->save();

        return [
            'event_id' => (int) $event->id,
            'duplicate' => false,
            'processed' => true,
            'payment_public_id' => $effect['payment_public_id'],
            'effect' => $effect['effect'],
        ];
    }

    /**
     * Route a verified event to the correct state effect.
     *
     * @param array<string, mixed> $payload
     * @return array{effect: string, payment_public_id: ?string}
     */
    private function applyEvent(string $gatewayCode, string $eventType, array $payload): array
    {
        $normalized = strtolower(str_replace(['.', '_'], ['.', '.'], $eventType));
        $payment = $this->matchPayment($gatewayCode, $payload);

        return match (true) {
            str_contains($normalized, 'refund') => $this->applyRefundEvent($payment, $normalized, $payload),
            str_contains($normalized, 'installment') => $this->applyInstallmentEvent($payment, $normalized, $payload),
            $this->isSuccessEvent($normalized) => $this->applyPaymentSuccess($payment, $payload),
            $this->isFailureEvent($normalized) => $this->applyPaymentFailure($payment, $normalized),
            default => throw InvalidWebhookException::unknownEventType($eventType),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{effect: string, payment_public_id: ?string}
     */
    private function applyPaymentSuccess(?Payment $payment, array $payload): array
    {
        $payment = $this->requirePayment($payment);

        $gatewayTxn = $this->extractString($payload, ['payment_id', 'paymentId', 'transaction_id', 'id'])
            ?? ('charge_' . (string) $payment->id);

        // Record the settled charge (idempotent via UNIQUE gateway_transaction_id).
        $transaction = $this->settleTransaction->execute(new SettleTransactionData(
            paymentId: (int) $payment->id,
            attemptId: $payment->latestAttempt()?->id,
            transactionType: PaymentTransaction::TYPE_CHARGE,
            gatewayTransactionId: (string) $gatewayTxn,
            amount: (string) $payment->amount,
            currency: (string) $payment->currency,
            metadata: ['source' => 'webhook'],
        ));

        $effect = 'already_settled';

        if ($transaction !== null) {
            $payment = $this->stateMachine->transition($payment, PaymentStatus::PAID);

            $order = $payment->order;

            if ($order !== null) {
                $this->orderSync->markPaid($order, null);
            }

            $effect = 'payment_paid';
        }

        return ['effect' => $effect, 'payment_public_id' => (string) $payment->public_id];
    }

    /**
     * @return array{effect: string, payment_public_id: ?string}
     */
    private function applyPaymentFailure(?Payment $payment, string $normalized): array
    {
        $payment = $this->requirePayment($payment);

        $to = str_contains($normalized, 'cancel') || str_contains($normalized, 'expire')
            ? PaymentStatus::CANCELLED
            : PaymentStatus::FAILED;

        $payment = $this->stateMachine->transition($payment, $to);

        // A cancelled payment fails the order (releases inventory via Orders).
        if ($to === PaymentStatus::CANCELLED) {
            $order = $payment->order;

            if ($order !== null) {
                $this->orderSync->markFailed($order, null);
            }
        }

        return ['effect' => 'payment_' . $to, 'payment_public_id' => (string) $payment->public_id];
    }

    /**
     * Refund lifecycle events: refund processed → settle refund transaction,
     * move payment to partially_refunded/refunded; refund failed → revert.
     *
     * @param array<string, mixed> $payload
     * @return array{effect: string, payment_public_id: ?string}
     */
    private function applyRefundEvent(?Payment $payment, string $normalized, array $payload): array
    {
        $payment = $this->requirePayment($payment);

        $refundId = $this->extractString($payload, ['refund_id', 'refundId', 'id']);

        /** @var \App\Modules\Payments\Infrastructure\Persistence\Models\Refund|null $refund */
        $refund = $refundId !== null
            ? $payment->refunds()->where('gateway_refund_id', $refundId)->first()
            : null;

        if ($refund === null) {
            // Unknown refund — cannot apply safely; let the replay surface it.
            throw PaymentNotFoundException::forPublicId((string) $payment->public_id);
        }

        $failed = str_contains($normalized, 'fail') || str_contains($normalized, 'reject');

        if ($failed) {
            $refund->status = \App\Modules\Payments\Infrastructure\Persistence\Models\Refund::STATUS_FAILED;
            $refund->save();

            // refund_pending → paid is NOT legal per the trigger; the payment
            // stays refund_pending until a successful refund arrives. Record
            // the failure only.
            return ['effect' => 'refund_failed', 'payment_public_id' => (string) $payment->public_id];
        }

        $refund->status = \App\Modules\Payments\Infrastructure\Persistence\Models\Refund::STATUS_PROCESSED;
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

        // Recompute the balance across ALL active refunds (this one is now
        // processed and still counts toward the cap).
        $remaining = $this->amounts->remainingRefundable($payment->fresh());

        // Compare at scale 2: fully refunded when nothing remains.
        $target = bccomp($remaining, '0.00', 2) === 0
            ? PaymentStatus::REFUNDED
            : PaymentStatus::PARTIALLY_REFUNDED;

        $payment = $this->stateMachine->transition($payment, $target);

        return ['effect' => 'refund_processed', 'payment_public_id' => (string) $payment->public_id];
    }

    /**
     * Installment settlement events delegate to SettleInstallmentAction via
     * the container (avoids a hard constructor cycle).
     *
     * @param array<string, mixed> $payload
     * @return array{effect: string, payment_public_id: ?string}
     */
    private function applyInstallmentEvent(?Payment $payment, string $normalized, array $payload): array
    {
        $payment = $this->requirePayment($payment);

        /** @var \App\Modules\Payments\Application\Actions\SettleInstallmentAction $settleInstallment */
        $settleInstallment = app(\App\Modules\Payments\Application\Actions\SettleInstallmentAction::class);

        $gatewayTxn = $this->extractString($payload, ['transaction_id', 'payment_id', 'id']) ?? '';

        $number = $this->extractString($payload, ['installment_number', 'installmentNumber', 'number']);

        if ($number !== null) {
            $installment = $payment->installmentPlan?->installments()
                ->where('installment_number', (int) $number)
                ->first();
        } else {
            $installment = $payment->installmentPlan?->installments()
                ->where('gateway_transaction_id', $gatewayTxn)
                ->first()
                ?? $payment->installmentPlan?->installments()
                    ->where('status', \App\Modules\Payments\Infrastructure\Persistence\Models\Installment::STATUS_PENDING)
                    ->orderBy('installment_number')
                    ->first();
        }

        if ($installment === null) {
            throw PaymentNotFoundException::forPublicId((string) $payment->public_id);
        }

        $settleInstallment->execute(new \App\Modules\Payments\Application\DTOs\SettleInstallmentData(
            installmentId: (int) $installment->id,
            gatewayTransactionId: (string) $gatewayTxn,
            metadata: ['source' => 'webhook'],
        ));

        return ['effect' => 'installment_settled', 'payment_public_id' => (string) $payment->public_id];
    }

    /**
     * Match a payment by the gateway session reference or our own public id
     * embedded in the payload (reference_code / merchant_reference / order).
     *
     * @param array<string, mixed> $payload
     */
    private function matchPayment(string $gatewayCode, array $payload): ?Payment
    {
        $candidates = array_values(array_filter([
            $this->extractString($payload, ['payment_id', 'paymentId']),
            $this->extractString($payload, ['checkout_id', 'checkoutId']),
            $this->extractString($payload, ['reference_code', 'referenceCode', 'merchant_reference', 'order.number']),
        ]));

        foreach ($candidates as $reference) {
            /** @var Payment|null $payment */
            $payment = Payment::where('gateway_payment_id', $reference)->first();

            if ($payment !== null) {
                return $payment;
            }

            // public_id is a UUID column — comparing a gateway session string
            // against it raises 22P02 in PostgreSQL. Only UUID-shaped
            // references may match our own public id.
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reference) === 1) {
                /** @var Payment|null $byPublicId */
                $byPublicId = Payment::where('public_id', $reference)->first();

                if ($byPublicId !== null) {
                    return $byPublicId;
                }
            }
        }

        return null;
    }

    private function requirePayment(?Payment $payment): Payment
    {
        if ($payment === null) {
            throw PaymentNotFoundException::forPublicId('(unmatched webhook reference)');
        }

        return $payment;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractString(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $cursor = $payload;

            foreach (explode('.', $path) as $segment) {
                if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                    $cursor = null;
                    break;
                }

                $cursor = $cursor[$segment];
            }

            if (is_scalar($cursor) && (string) $cursor !== '') {
                return (string) $cursor;
            }
        }

        return null;
    }

    private function isSuccessEvent(string $normalized): bool
    {
        return (bool) preg_match('/(paid|captured|authorized|settled|completed|succeeded)/', $normalized);
    }

    private function isFailureEvent(string $normalized): bool
    {
        return (bool) preg_match('/(fail|cancel|expire|declined|rejected)/', $normalized);
    }

    private function markFailed(PaymentWebhookEvent $event, string $reason): void
    {
        $event->processed = false;
        $event->error_message = mb_substr($reason, 0, 500);
        $event->save();
    }
}
