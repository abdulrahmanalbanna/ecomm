<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions;

use App\Modules\Inventory\Application\DTOs\StockBatchReservationData;
use App\Modules\Inventory\Domain\Contracts\InventoryRepositoryContract;
use App\Modules\Inventory\Domain\Events\InventoryReserved;
use App\Modules\Inventory\Domain\Exceptions\InvalidReservationException;

class ReserveInventoryBatchAction
{
    public function __construct(
        private readonly InventoryRepositoryContract $repository
    ) {
    }

    public function execute(StockBatchReservationData $dto): array
    {
        if (empty($dto->items)) {
            throw new InvalidReservationException('Batch reservation requires at least one item.');
        }

        if ($dto->orderId === null) {
            throw new InvalidReservationException('Order ID is required for batch inventory reservation.');
        }

        $variantIds = [];
        $quantities = [];
        foreach ($dto->items as $item) {
            $variantIds[] = (int) $item['variant_id'];
            $quantities[] = (int) $item['quantity'];
        }

        if (count(array_unique($variantIds)) !== count($variantIds)) {
            throw new InvalidReservationException('Duplicate variant IDs are not allowed in batch reservation.');
        }

        $reservationIds = $this->repository->reserveBatch(
            $dto->orderId,
            $variantIds,
            $quantities,
            $dto->createdBy
        );

        foreach ($reservationIds as $index => $resId) {
            InventoryReserved::dispatch(
                $resId,
                $dto->orderId,
                $variantIds[$index],
                $quantities[$index],
                $dto->createdBy
            );
        }

        return $reservationIds;
    }
}
