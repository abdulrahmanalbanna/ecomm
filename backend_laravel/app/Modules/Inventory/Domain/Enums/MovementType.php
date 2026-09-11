<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum MovementType: string
{
    case PURCHASE    = 'purchase';
    case SALE        = 'sale';
    case RETURN      = 'return';
    case ADJUSTMENT  = 'adjustment';
    case RESERVATION = 'reservation';
    case RELEASE     = 'release';
}
