<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum ReservationStatus: string
{
    case ACTIVE    = 'active';
    case RELEASED  = 'released';
    case EXPIRED   = 'expired';
    case CANCELLED = 'cancelled';
    case CONVERTED = 'converted';
}
