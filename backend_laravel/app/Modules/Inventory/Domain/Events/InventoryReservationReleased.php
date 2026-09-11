<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventoryReservationReleased
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $reservationId,
        public readonly string $status,
        public readonly ?int $actorId = null
    ) {
    }
}
