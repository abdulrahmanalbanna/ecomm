<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Repositories;

use App\Modules\Inventory\Domain\Contracts\InventoryRepositoryContract;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Exceptions\DuplicateReservationException;
use App\Modules\Inventory\Domain\Exceptions\InsufficientInventoryException;
use App\Modules\Inventory\Domain\Exceptions\InventoryNotFoundException;
use App\Modules\Inventory\Domain\Exceptions\ReservationAlreadyConvertedException;
use App\Modules\Inventory\Domain\Exceptions\ReservationAlreadyReleasedException;
use App\Modules\Inventory\Infrastructure\Persistence\Models\Inventory;
use App\Modules\Inventory\Infrastructure\Persistence\Models\InventoryMovement;
use App\Modules\Inventory\Infrastructure\Persistence\Models\InventoryReservation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class InventoryRepository implements InventoryRepositoryContract
{
    public function findByVariantId(int $variantId): ?Inventory
    {
        return Inventory::where('variant_id', $variantId)->first();
    }

    public function findReservationById(int $reservationId): ?InventoryReservation
    {
        return InventoryReservation::find($reservationId);
    }

    public function findActiveReservationsForOrder(int $orderId): Collection
    {
        return InventoryReservation::where('order_id', $orderId)->active()->get();
    }

    public function reserve(int $orderId, int $variantId, int $quantity, ?int $actorId = null, ?string $expiresAt = null): int
    {
        try {
            $result = DB::selectOne(
                'SELECT fn_reserve_inventory_v2(?, ?, ?, ?, ?) AS res_id',
                [$orderId, $variantId, $quantity, $actorId, $expiresAt]
            );

            return (int) $result->res_id;
        } catch (QueryException $e) {
            $this->handlePostgresException($e, $variantId);
            throw $e;
        }
    }

    public function reserveBatch(int $orderId, array $variantIds, array $quantities, ?int $actorId = null, ?string $expiresAt = null): array
    {
        try {
            $pgVariantIds = '{' . implode(',', $variantIds) . '}';
            $pgQuantities = '{' . implode(',', $quantities) . '}';

            $result = DB::selectOne(
                'SELECT fn_reserve_inventory_batch_v2(?, ?::BIGINT[], ?::INT[], ?, ?) AS res_ids',
                [$orderId, $pgVariantIds, $pgQuantities, $actorId, $expiresAt]
            );

            if (empty($result->res_ids)) {
                return [];
            }

            // Parse PostgreSQL array output e.g. "{10,11}"
            $resIdsStr = trim((string) $result->res_ids, '{}');
            if (empty($resIdsStr)) {
                return [];
            }

            return array_map('intval', explode(',', $resIdsStr));
        } catch (QueryException $e) {
            $this->handlePostgresException($e, $variantIds[0] ?? 0);
            throw $e;
        }
    }

    public function releaseReservation(int $reservationId, string $newStatus = 'released', ?int $actorId = null): void
    {
        try {
            DB::statement(
                'SELECT fn_release_inventory_reservation(?, ?, ?)',
                [$reservationId, $newStatus, $actorId]
            );
        } catch (QueryException $e) {
            $sqlState = $e->getCode();
            if ($sqlState === 'P0006') {
                throw new ReservationAlreadyReleasedException("Reservation {$reservationId} is already released or non-active.", 0, $e);
            }
            if ($sqlState === 'P0002') {
                throw new InventoryNotFoundException("Reservation {$reservationId} not found.", 0, $e);
            }
            throw $e;
        }
    }

    public function convertReservation(int $reservationId, ?int $actorId = null): void
    {
        try {
            DB::statement(
                'SELECT fn_convert_inventory_reservation(?, ?)',
                [$reservationId, $actorId]
            );
        } catch (QueryException $e) {
            $sqlState = $e->getCode();
            if ($sqlState === 'P0007') {
                throw new ReservationAlreadyConvertedException("Reservation {$reservationId} is already converted or non-active.", 0, $e);
            }
            if ($sqlState === 'P0002') {
                throw new InventoryNotFoundException("Reservation {$reservationId} not found.", 0, $e);
            }
            throw $e;
        }
    }

    public function receiveStock(int $variantId, int $quantity, ?string $note = null, ?int $actorId = null): void
    {
        DB::transaction(function () use ($variantId, $quantity, $note, $actorId) {
            $inventory = Inventory::where('variant_id', $variantId)->lockForUpdate()->first();
            if (! $inventory) {
                throw new InventoryNotFoundException("Inventory not found for variant {$variantId}");
            }

            $backorderFulfill = min($quantity, $inventory->quantity_backordered);

            $inventory->quantity_on_hand += $quantity;
            $inventory->quantity_backordered -= $backorderFulfill;
            $inventory->save();

            if ($backorderFulfill > 0) {
                $remainingToFulfill = $backorderFulfill;
                $activeReservations = InventoryReservation::where('variant_id', $variantId)
                    ->where('status', 'active')
                    ->where('quantity_backordered', '>', 0)
                    ->orderBy('created_at', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($activeReservations as $res) {
                    if ($remainingToFulfill <= 0) {
                        break;
                    }
                    $deduct = min($remainingToFulfill, $res->quantity_backordered);
                    $res->quantity_backordered -= $deduct;
                    $res->save();
                    $remainingToFulfill -= $deduct;
                }
            }

            InventoryMovement::create([
                'variant_id'              => $variantId,
                'movement_type'           => MovementType::PURCHASE,
                'quantity_delta'          => $quantity,
                'quantity_on_hand_delta'  => $quantity,
                'quantity_reserved_delta' => 0,
                'on_hand_after'           => $inventory->quantity_on_hand,
                'quantity_on_hand_after'  => $inventory->quantity_on_hand,
                'quantity_reserved_after' => $inventory->quantity_reserved,
                'note'                    => $note,
                'created_by'              => $actorId,
            ]);
        });
    }

    public function adjustStock(int $variantId, int $quantityDelta, ?string $note = null, ?int $actorId = null): void
    {
        DB::transaction(function () use ($variantId, $quantityDelta, $note, $actorId) {
            $inventory = Inventory::where('variant_id', $variantId)->lockForUpdate()->first();
            if (! $inventory) {
                throw new InventoryNotFoundException("Inventory not found for variant {$variantId}");
            }

            $newOnHand = $inventory->quantity_on_hand + $quantityDelta;
            if ($newOnHand < 0) {
                throw new InsufficientInventoryException("Adjusting stock by {$quantityDelta} results in negative stock.");
            }

            $inventory->quantity_on_hand = $newOnHand;
            $inventory->save();

            InventoryMovement::create([
                'variant_id'              => $variantId,
                'movement_type'           => MovementType::ADJUSTMENT,
                'quantity_delta'          => $quantityDelta,
                'quantity_on_hand_delta'  => $quantityDelta,
                'quantity_reserved_delta' => 0,
                'on_hand_after'           => $newOnHand,
                'quantity_on_hand_after'  => $newOnHand,
                'quantity_reserved_after' => $inventory->quantity_reserved,
                'note'                    => $note,
                'created_by'              => $actorId,
            ]);
        });
    }

    public function returnStock(int $variantId, int $quantity, ?int $orderId = null, ?string $note = null, ?int $actorId = null): void
    {
        DB::transaction(function () use ($variantId, $quantity, $orderId, $note, $actorId) {
            $inventory = Inventory::where('variant_id', $variantId)->lockForUpdate()->first();
            if (! $inventory) {
                throw new InventoryNotFoundException("Inventory not found for variant {$variantId}");
            }

            $inventory->quantity_on_hand += $quantity;
            $inventory->save();

            InventoryMovement::create([
                'variant_id'              => $variantId,
                'order_id'                => $orderId,
                'movement_type'           => MovementType::RETURN,
                'quantity_delta'          => $quantity,
                'quantity_on_hand_delta'  => $quantity,
                'quantity_reserved_delta' => 0,
                'on_hand_after'           => $inventory->quantity_on_hand,
                'quantity_on_hand_after'  => $inventory->quantity_on_hand,
                'quantity_reserved_after' => $inventory->quantity_reserved,
                'note'                    => $note,
                'created_by'              => $actorId,
            ]);
        });
    }

    public function updateSettings(int $variantId, ?int $reorderPoint = null, ?int $reorderQuantity = null, ?bool $allowBackorder = null): Inventory
    {
        $inventory = Inventory::where('variant_id', $variantId)->first();
        if (! $inventory) {
            throw new InventoryNotFoundException("Inventory not found for variant {$variantId}");
        }

        if ($reorderPoint !== null) {
            $inventory->reorder_point = $reorderPoint;
        }
        if ($reorderQuantity !== null) {
            $inventory->reorder_quantity = $reorderQuantity;
        }
        if ($allowBackorder !== null) {
            $inventory->allow_backorder = $allowBackorder;
        }

        $inventory->save();

        return $inventory;
    }

    public function getLowStock(int $perPage = 15): LengthAwarePaginator
    {
        return Inventory::lowStock()->paginate($perPage);
    }

    public function getMovements(int $variantId, int $perPage = 15): LengthAwarePaginator
    {
        return InventoryMovement::where('variant_id', $variantId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    private function handlePostgresException(QueryException $e, int $variantId): void
    {
        $sqlState = $e->getCode();
        $message = $e->getMessage();

        if ($sqlState === 'P0002') {
            throw new InventoryNotFoundException("Inventory record not found for variant {$variantId}", 0, $e);
        }
        if ($sqlState === 'P0003') {
            throw new InsufficientInventoryException("Insufficient inventory available for variant {$variantId}", 0, $e);
        }
        if ($sqlState === 'P0005') {
            throw new DuplicateReservationException("Duplicate active reservation attempt for variant {$variantId}", 0, $e);
        }
    }
}
