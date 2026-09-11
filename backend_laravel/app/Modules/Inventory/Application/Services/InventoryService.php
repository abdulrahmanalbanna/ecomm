<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Application\Actions\AdjustStockAction;
use App\Modules\Inventory\Application\Actions\ConvertInventoryReservationAction;
use App\Modules\Inventory\Application\Actions\ReceiveStockAction;
use App\Modules\Inventory\Application\Actions\ReleaseInventoryReservationAction;
use App\Modules\Inventory\Application\Actions\ReserveInventoryAction;
use App\Modules\Inventory\Application\Actions\ReserveInventoryBatchAction;
use App\Modules\Inventory\Application\Actions\ReturnStockAction;
use App\Modules\Inventory\Application\Actions\UpdateInventorySettingsAction;
use App\Modules\Inventory\Application\DTOs\InventorySettingsData;
use App\Modules\Inventory\Application\DTOs\StockAdjustmentData;
use App\Modules\Inventory\Application\DTOs\StockBatchReservationData;
use App\Modules\Inventory\Application\DTOs\StockDeductionData;
use App\Modules\Inventory\Application\DTOs\StockReceiveData;
use App\Modules\Inventory\Application\DTOs\StockReleaseData;
use App\Modules\Inventory\Application\DTOs\StockReservationData;
use App\Modules\Inventory\Application\DTOs\StockReturnData;
use App\Modules\Inventory\Application\Queries\GetInventoryQuery;
use App\Modules\Inventory\Application\Queries\GetLowStockQuery;
use App\Modules\Inventory\Domain\Contracts\InventoryRepositoryContract;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Exceptions\InsufficientInventoryException;
use App\Modules\Inventory\Domain\Exceptions\InventoryNotFoundException;
use App\Modules\Inventory\Infrastructure\Persistence\Models\Inventory;
use App\Modules\Inventory\Infrastructure\Persistence\Models\InventoryReservation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(
        private readonly InventoryRepositoryContract $repository,
        private readonly ReceiveStockAction $receiveStockAction,
        private readonly AdjustStockAction $adjustStockAction,
        private readonly ReturnStockAction $returnStockAction,
        private readonly ReserveInventoryAction $reserveInventoryAction,
        private readonly ReserveInventoryBatchAction $reserveInventoryBatchAction,
        private readonly ReleaseInventoryReservationAction $releaseInventoryReservationAction,
        private readonly ConvertInventoryReservationAction $convertInventoryReservationAction,
        private readonly UpdateInventorySettingsAction $updateInventorySettingsAction,
        private readonly GetInventoryQuery $getInventoryQuery,
        private readonly GetLowStockQuery $getLowStockQuery
    ) {
    }

    public function getOrCreateForVariant(int $variantId): Inventory
    {
        $inventory = $this->repository->findByVariantId($variantId);
        if (! $inventory) {
            if (! DB::table('product_variants')->where('id', $variantId)->exists()) {
                throw new InventoryNotFoundException("Inventory record not found for variant ID {$variantId}.");
            }
            $inventory = Inventory::create([
                'variant_id'        => $variantId,
                'quantity_on_hand'  => 0,
                'quantity_reserved' => 0,
                'reorder_point'     => 5,
                'reorder_quantity'  => 20,
                'allow_backorder'   => false,
            ]);
            $inventory->refresh();
        }

        return $inventory;
    }

    public function getInventory(int $variantId): Inventory
    {
        $this->getOrCreateForVariant($variantId);

        return $this->getInventoryQuery->execute($variantId);
    }

    public function listInventory(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Inventory::query();

        if (! empty($filters['search'])) {
            $search = '%' . trim((string) $filters['search']) . '%';
            $query->where('variant_id', 'LIKE', $search);
        }

        if (isset($filters['in_stock']) && $filters['in_stock'] !== '') {
            if (filter_var($filters['in_stock'], FILTER_VALIDATE_BOOLEAN)) {
                $query->inStock();
            } else {
                $query->where('quantity_available', '<=', 0);
            }
        }

        return $query->orderBy('updated_at', 'desc')->paginate($perPage);
    }

    public function getLowStock(int $perPage = 15): LengthAwarePaginator
    {
        return $this->getLowStockQuery->execute($perPage);
    }

    public function updateSettings(int $variantId, InventorySettingsData $dto): Inventory
    {
        $this->getOrCreateForVariant($variantId);

        return $this->updateInventorySettingsAction->execute($variantId, $dto);
    }

    public function receiveStock(StockReceiveData $dto): Inventory
    {
        $this->getOrCreateForVariant($dto->variantId);

        return $this->receiveStockAction->execute($dto);
    }

    public function adjustStock(StockAdjustmentData $dto): Inventory
    {
        $this->getOrCreateForVariant($dto->variantId);

        return $this->adjustStockAction->execute($dto);
    }

    public function reserveStock(StockReservationData $dto): int
    {
        $this->getOrCreateForVariant($dto->variantId);
        $orderId = $dto->orderId ?? (int) (microtime(true) * 1000);

        return $this->reserveInventoryAction->execute(new StockReservationData(
            variantId: $dto->variantId,
            quantity: $dto->quantity,
            createdBy: $dto->createdBy,
            orderId: $orderId
        ));
    }

    public function reserveStockBatch(StockBatchReservationData $dto): void
    {
        foreach ($dto->items as $item) {
            $this->getOrCreateForVariant((int) $item['variant_id']);
        }
        $this->reserveInventoryBatchAction->execute($dto);
    }

    public function releaseStock(StockReleaseData $dto): Inventory
    {
        if ($dto->reservationId !== null) {
            $reservation = $this->repository->findReservationById($dto->reservationId);
            if (! $reservation) {
                throw new InventoryNotFoundException("Reservation {$dto->reservationId} not found.");
            }
        } else {
            $query = InventoryReservation::where('variant_id', $dto->variantId)->active();
            if ($dto->orderId !== null) {
                $query->where('order_id', $dto->orderId);
            }
            $reservation = $query->first();

            if (! $reservation) {
                throw new InventoryNotFoundException("No active reservation found for variant {$dto->variantId}.");
            }
        }

        $releaseQty = $dto->quantity > 0 ? $dto->quantity : $reservation->quantity;

        if ($releaseQty > $reservation->quantity) {
            throw new InsufficientInventoryException(
                "Cannot release {$releaseQty} units: only {$reservation->quantity} are reserved."
            );
        }

        if ($releaseQty === $reservation->quantity) {
            // Full release via the atomic PG function
            $this->releaseInventoryReservationAction->execute($reservation->id, 'released', $dto->createdBy);
        } else {
            // Partial release handled in PHP inside a transaction
            $this->partialReleaseReservation($reservation, $releaseQty, $dto->createdBy);
        }

        return $this->getInventory($dto->variantId);
    }

    private function partialReleaseReservation(InventoryReservation $reservation, int $quantity, ?int $actorId): void
    {
        DB::transaction(function () use ($reservation, $quantity, $actorId) {
            // Lock inventory row
            $inventory = \App\Modules\Inventory\Infrastructure\Persistence\Models\Inventory
                ::where('variant_id', $reservation->variant_id)
                ->lockForUpdate()
                ->firstOrFail();

            $onHandNow   = $inventory->quantity_on_hand;
            $reservedNow = $inventory->quantity_reserved;

            // Backorder portion to release proportionally
            $backorderRelease = 0;
            if ($reservation->quantity_backordered > 0) {
                $backorderRelease = (int) floor(
                    $reservation->quantity_backordered * ($quantity / $reservation->quantity)
                );
            }

            // Update inventory counters
            $inventory->quantity_reserved    = max(0, $reservedNow - $quantity);
            $inventory->quantity_backordered = max(0, $inventory->quantity_backordered - $backorderRelease);
            $inventory->save();

            // Shrink reservation by the released quantity
            $reservation->quantity             -= $quantity;
            $reservation->quantity_backordered -= $backorderRelease;
            $reservation->save();

            // Write ledger row
            \App\Modules\Inventory\Infrastructure\Persistence\Models\InventoryMovement::create([
                'variant_id'              => $reservation->variant_id,
                'order_id'               => $reservation->order_id,
                'reservation_id'         => $reservation->id,
                'movement_type'          => MovementType::RELEASE,
                'quantity_delta'         => $quantity,
                'quantity_on_hand_delta' => 0,
                'quantity_reserved_delta'=> -$quantity,
                'on_hand_after'          => $onHandNow,
                'quantity_on_hand_after' => $onHandNow,
                'quantity_reserved_after'=> max(0, $reservedNow - $quantity),
                'created_by'             => $actorId,
            ]);
        });
    }

    public function deductStock(StockDeductionData $dto): Inventory
    {
        if ($dto->reservationId !== null) {
            $this->convertInventoryReservationAction->execute($dto->reservationId, $dto->createdBy);
        } else {
            $query = InventoryReservation::where('variant_id', $dto->variantId)->active();
            if ($dto->orderId !== null) {
                $query->where('order_id', $dto->orderId);
            }
            $reservation = $query->first();

            if (! $reservation) {
                throw new InventoryNotFoundException("No active reservation found for variant {$dto->variantId}.");
            }

            $this->convertInventoryReservationAction->execute($reservation->id, $dto->createdBy);
        }

        return $this->getInventory($dto->variantId);
    }

    public function returnStock(StockReturnData $dto): Inventory
    {
        $this->getOrCreateForVariant($dto->variantId);

        return $this->returnStockAction->execute($dto);
    }
}
