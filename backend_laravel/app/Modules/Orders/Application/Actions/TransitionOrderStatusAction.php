<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Inventory\Application\DTOs\StockDeductionData;
use App\Modules\Inventory\Application\DTOs\StockReleaseData;
use App\Modules\Inventory\Application\Services\InventoryService;
use App\Modules\Orders\Domain\Exceptions\InvalidStatusTransitionException;
use App\Modules\Orders\Domain\Exceptions\OrderNotFoundException;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use App\Modules\Orders\Infrastructure\Persistence\Models\OrderEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * TransitionOrderStatusAction — the single lifecycle entry point.
 *
 * Enforces the order state machine. The PHP adjacency map (OrderStatus)
 * mirrors fn_validate_order_status_transition() for friendly 422s, but the
 * PostgreSQL trigger trg_orders_status_transition is the authoritative guard:
 * any illegal write raises P0001 and is translated here.
 *
 * Inventory side effects (inside the same transaction, via the Inventory
 * module — no second mechanism):
 *  - → cancelled / failed : release the order's reservations
 *    (fn_release_inventory_reservation). Safe because physical deduction only happens at
 *    'delivered', which is unreachable from cancelled/failed.
 *  - → delivered          : deduct stock (fn_convert_inventory_reservation), converting
 *    the reservation to sale movement.
 *
 * Lifecycle timestamps (confirmed_at/shipped_at/delivered_at/cancelled_at)
 * are set automatically by the DB trigger — never by application code.
 *
 * Every successful transition appends exactly one order_events row
 * (append-only; events are never updated or deleted).
 */
class TransitionOrderStatusAction
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata Context stored on the event row
     */
    public function execute(Order $order, string $toStatus, ?int $actorId = null, ?string $note = null, array $metadata = []): Order
    {
        if (! OrderStatus::isKnown($toStatus)) {
            throw new InvalidStatusTransitionException("Unknown order status '{$toStatus}'.");
        }

        return DB::transaction(function () use ($order, $toStatus, $actorId, $note, $metadata): Order {
            // Re-read with a row lock so concurrent transitions serialize.
            /** @var Order $locked */
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            $from = $locked->status;

            if (! OrderStatus::canTransition($from, $toStatus)) {
                throw InvalidStatusTransitionException::between($from, $toStatus);
            }

            // Apply inventory side effects BEFORE the status write so any
            // failure (e.g. release exceeding reserved) rolls everything back.
            if ($toStatus === OrderStatus::CANCELLED || $toStatus === OrderStatus::FAILED) {
                $this->releaseReservations($locked, $actorId);
            } elseif ($toStatus === OrderStatus::DELIVERED) {
                $this->deductStock($locked, $actorId);
            }

            try {
                $locked->status = $toStatus;
                $locked->save();
            } catch (QueryException $e) {
                // The DB trigger is authoritative; map its rejection to the
                // domain exception in case the PHP map ever drifts.
                if ((string) $e->getCode() === 'P0001' || str_contains($e->getMessage(), 'Invalid order status transition')) {
                    throw InvalidStatusTransitionException::between($from, $toStatus);
                }
                throw $e;
            }

            OrderEvent::create([
                'order_id'     => $locked->id,
                'from_status'  => $from,
                'to_status'    => $toStatus,
                'triggered_by' => $actorId,
                'note'         => $note,
                'metadata'     => (object) $metadata,
                'created_at'   => now(),
            ]);

            return $locked->fresh(['items', 'events']);
        });
    }

    public function findForUser(int $userId, string $publicId): Order
    {
        $order = Order::forUser($userId)->where('public_id', $publicId)->first();

        if ($order === null) {
            throw OrderNotFoundException::forPublicId($publicId);
        }

        return $order;
    }

    public function findForAdmin(string $publicId): Order
    {
        $order = Order::where('public_id', $publicId)->first();

        if ($order === null) {
            throw OrderNotFoundException::forPublicId($publicId);
        }

        return $order;
    }

    private function releaseReservations(Order $order, ?int $actorId): void
    {
        foreach ($order->items as $item) {
            $this->inventoryService->releaseStock(new StockReleaseData(
                variantId: (int) $item->variant_id,
                quantity: (int) $item->quantity,
                createdBy: $actorId,
                orderId: $order->id,
            ));
        }
    }

    private function deductStock(Order $order, ?int $actorId): void
    {
        foreach ($order->items as $item) {
            $this->inventoryService->deductStock(new StockDeductionData(
                variantId: (int) $item->variant_id,
                quantity: (int) $item->quantity,
                createdBy: $actorId,
                orderId: $order->id,
            ));
        }
    }
}
