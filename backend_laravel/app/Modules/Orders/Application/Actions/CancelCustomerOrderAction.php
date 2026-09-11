<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Modules\Orders\Domain\Exceptions\OrderNotCancellableException;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Persistence\Models\Order;

/**
 * Customer self-cancellation.
 *
 * Restricted to the pre-fulfillment window (pending / payment_pending).
 * Delegates the actual transition to TransitionOrderStatusAction so the
 * state machine, inventory release, and event append happen in exactly
 * one place.
 */
class CancelCustomerOrderAction
{
    public function __construct(
        private readonly TransitionOrderStatusAction $transitionAction,
    ) {
    }

    public function execute(User $user, string $publicId, ?string $reason = null): Order
    {
        $order = $this->transitionAction->findForUser($user->id, $publicId);

        if (! in_array($order->status, OrderStatus::customerCancellableStatuses(), true)) {
            throw OrderNotCancellableException::customerWindow($order->status);
        }

        return $this->transitionAction->execute(
            order: $order,
            toStatus: OrderStatus::CANCELLED,
            actorId: $user->id,
            note: $reason !== null ? "Customer cancellation: {$reason}" : 'Cancelled by customer.',
            metadata: ['channel' => 'customer', 'reason' => $reason],
        );
    }
}
