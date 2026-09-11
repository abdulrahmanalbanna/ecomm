<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Services;

use App\Modules\Orders\Application\Actions\TransitionOrderStatusAction;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Persistence\Models\Order;

/**
 * OrderPaymentSyncService — the Payments module's ONLY touchpoint with the
 * Orders lifecycle.
 *
 * It does NOT reimplement the order state machine: every write is delegated
 * to Orders' TransitionOrderStatusAction (which enforces the PHP map, the
 * P0001 DB trigger, order_events, and inventory side effects). This service
 * only decides WHEN a payment outcome should move the order, and guards each
 * attempt with OrderStatus::canTransition so a non-payments actor (e.g. an
 * admin already moved the order) is never fought over.
 *
 * Mapping:
 *  - payment intent created        : pending → payment_pending
 *  - payment captured (paid)       : payment_pending → confirmed
 *  - payment cancelled (explicit)  : payment_pending → failed (releases stock)
 *  - gateway DECLINE               : order untouched — payment stays retryable
 */
final class OrderPaymentSyncService
{
    public function __construct(
        private readonly TransitionOrderStatusAction $transitionOrder,
    ) {
    }

    public function markPaymentPending(Order $order, ?int $actorId = null): Order
    {
        if (OrderStatus::canTransition((string) $order->status, OrderStatus::PAYMENT_PENDING)) {
            return $this->transitionOrder->execute(
                $order,
                OrderStatus::PAYMENT_PENDING,
                $actorId,
                'Payment intent created',
                ['source' => 'payments'],
            );
        }

        return $order;
    }

    public function markPaid(Order $order, ?int $actorId = null): Order
    {
        // A pending order must pass through payment_pending first — the order
        // trigger forbids pending → confirmed.
        $order = $this->markPaymentPending($order, $actorId);

        if (OrderStatus::canTransition((string) $order->status, OrderStatus::CONFIRMED)) {
            return $this->transitionOrder->execute(
                $order,
                OrderStatus::CONFIRMED,
                $actorId,
                'Payment captured',
                ['source' => 'payments'],
            );
        }

        return $order;
    }

    public function markFailed(Order $order, ?int $actorId = null): Order
    {
        if (OrderStatus::canTransition((string) $order->status, OrderStatus::FAILED)) {
            return $this->transitionOrder->execute(
                $order,
                OrderStatus::FAILED,
                $actorId,
                'Payment cancelled',
                ['source' => 'payments'],
            );
        }

        return $order;
    }
}
