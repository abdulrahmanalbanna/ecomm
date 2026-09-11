<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions;

use App\Modules\Inventory\Domain\Contracts\InventoryRepositoryContract;
use App\Modules\Inventory\Domain\Events\InventoryReservationConverted;

class ConvertInventoryReservationAction
{
    public function __construct(
        private readonly InventoryRepositoryContract $repository
    ) {
    }

    public function execute(int $reservationId, ?int $actorId = null): void
    {
        $this->repository->convertReservation($reservationId, $actorId);

        InventoryReservationConverted::dispatch(
            $reservationId,
            $actorId
        );
    }
}
