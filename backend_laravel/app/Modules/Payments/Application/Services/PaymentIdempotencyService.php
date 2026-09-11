<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Services;

use App\Modules\Payments\Domain\Exceptions\DuplicatePaymentException;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentTransaction;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentWebhookEvent;
use Illuminate\Support\Str;

/**
 * PaymentIdempotencyService — reusable helpers implementing the idempotency
 * guarantees of the baseline schema (database/sql/011_payments.sql):
 *
 *  - payments.idempotency_key UNIQUE:
 *      * same key + same order  → return the existing payment (safe replay)
 *      * same key + other order → DuplicatePaymentException (409)
 *  - payment_transactions.gateway_transaction_id UNIQUE:
 *      INSERT ... ON CONFLICT DO NOTHING → double-booking is impossible.
 *  - payment_webhook_events.gateway_event_id UNIQUE:
 *      INSERT ... ON CONFLICT DO NOTHING → re-delivered webhooks are no-ops.
 *
 * All methods are stateless; the DB constraints remain the final authority.
 */
final class PaymentIdempotencyService
{
    public function generateKey(string $prefix = 'pay'): string
    {
        return $prefix . '_' . Str::uuid()->toString();
    }

    /**
     * Look up an existing payment by idempotency key.
     */
    public function findPaymentByKey(string $idempotencyKey): ?Payment
    {
        /** @var Payment|null $payment */
        return Payment::where('idempotency_key', $idempotencyKey)->first();
    }

    /**
     * Resolve an idempotency-key replay:
     *  - no existing payment          → null (caller proceeds to create)
     *  - existing payment, same order → that payment (replay, return it)
     *  - existing payment, other order→ 409 keyConflict
     */
    public function resolvePaymentReplay(string $idempotencyKey, int $orderId): ?Payment
    {
        $existing = $this->findPaymentByKey($idempotencyKey);

        if ($existing === null) {
            return null;
        }

        if ((int) $existing->order_id === $orderId) {
            return $existing;
        }

        throw DuplicatePaymentException::keyConflict($idempotencyKey);
    }

    /**
     * Idempotently insert a settled transaction row.
     *
     * @param array<string, mixed> $attributes
     * @return PaymentTransaction|null null when the gateway transaction id already exists (duplicate)
     */
    public function insertTransactionIfAbsent(array $attributes): ?PaymentTransaction
    {
        $gatewayTransactionId = (string) $attributes['gateway_transaction_id'];

        $exists = PaymentTransaction::where('gateway_transaction_id', $gatewayTransactionId)->exists();

        if ($exists) {
            return null;
        }

        try {
            /** @var PaymentTransaction $txn */
            $txn = PaymentTransaction::create($attributes);

            return $txn;
        } catch (\Illuminate\Database\QueryException $e) {
            // 23505 unique_violation on gateway_transaction_id → concurrent
            // duplicate; treat as no-op (the other writer won).
            if ((string) $e->getCode() === '23505') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Idempotently persist a raw webhook event (INSERT ... ON CONFLICT DO
     * NOTHING semantics via exists()+catch).
     *
     * @param array<string, mixed> $attributes
     * @return PaymentWebhookEvent|null null when the event was already stored (re-delivery)
     */
    public function insertWebhookEventIfAbsent(array $attributes): ?PaymentWebhookEvent
    {
        $eventId = (string) $attributes['gateway_event_id'];

        $exists = PaymentWebhookEvent::where('gateway_event_id', $eventId)->exists();

        if ($exists) {
            return null;
        }

        try {
            /** @var PaymentWebhookEvent $event */
            $event = PaymentWebhookEvent::create($attributes);

            return $event;
        } catch (\Illuminate\Database\QueryException $e) {
            if ((string) $e->getCode() === '23505') {
                return null;
            }

            throw $e;
        }
    }
}
