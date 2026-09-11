<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions;

use App\Modules\Inventory\Application\DTOs\StockReservationData;
use App\Modules\Inventory\Domain\Contracts\InventoryRepositoryContract;
use App\Modules\Inventory\Domain\Events\InventoryReserved;
use App\Modules\Inventory\Domain\Exceptions\InvalidReservationException;

class ReserveInventoryAction
{
    public function __construct(
        private readonly InventoryRepositoryContract $repository
    ) {
    }

    public function execute(StockReservationData $dto): int
    {
        if ($dto->quantity <= 0) {
            throw new InvalidReservationException('Reservation quantity must be positive.');
        }

        if ($dto->orderId === null) {
            throw new InvalidReservationException('Order ID is required for stock reservation.');
        }

        $reservationId = $this->repository->reserve(
            $dto->orderId,
            $dto->variantId,
            $dto->quantity,
            $dto->createdBy
        );

        InventoryReserved::dispatch(
            $reservationId,
            $dto->orderId,
            $dto->variantId,
            $dto->quantity,
            $dto->createdBy
        );

        return $reservationId;
    }
}
