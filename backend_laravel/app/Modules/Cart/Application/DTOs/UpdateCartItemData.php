<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application\DTOs;

final class UpdateCartItemData
{
    public function __construct(
        public readonly int $quantity
    ) {
    }
}
