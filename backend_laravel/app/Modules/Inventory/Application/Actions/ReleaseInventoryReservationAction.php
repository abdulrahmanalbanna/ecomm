<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions;

use App\Modules\Inventory\Domain\Contracts\InventoryRepositoryContract;
use App\Modules\Inventory\Domain\Events\InventoryReservationReleased;

class ReleaseInventoryReservationAction
{
    public function __construct(
        private readonly InventoryRepositoryContract $repository
    ) {
    }

    public function execute(int $reservationId, string $newStatus = 'released', ?int $actorId = null): void
    {
        $this->repository->releaseReservation($reservationId, $newStatus, $actorId);

        InventoryReservationReleased::dispatch(
            $reservationId,
            $newStatus,
            $actorId
        );
    }
}
