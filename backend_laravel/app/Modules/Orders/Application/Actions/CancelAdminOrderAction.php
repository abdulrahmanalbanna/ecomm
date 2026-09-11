<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Orders\Domain\Exceptions\OrderNotCancellableException;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Persistence\Models\Order;

/**
 * Admin cancellation — permitted from any state the DB state machine allows
 * (pending, payment_pending, confirmed, processing). Beyond that the order
 * is already shipped/delivered/terminal and cannot be cancelled.
 */
class CancelAdminOrderAction
{
    public function __construct(
        private readonly TransitionOrderStatusAction $transitionAction,
    ) {
    }

    public function execute(string $publicId, int $actorId, ?string $reason = null): Order
    {
        $order = $this->transitionAction->findForAdmin($publicId);

        if (! in_array($order->status, OrderStatus::cancellableStatuses(), true)) {
            throw OrderNotCancellableException::fromStatus($order->status);
        }

        return $this->transitionAction->execute(
            order: $order,
            toStatus: OrderStatus::CANCELLED,
            actorId: $actorId,
            note: $reason !== null ? "Admin cancellation: {$reason}" : 'Cancelled by staff.',
            metadata: ['channel' => 'admin', 'reason' => $reason],
        );
    }
}
