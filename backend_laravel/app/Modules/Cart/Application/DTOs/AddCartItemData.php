<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application\DTOs;

final class AddCartItemData
{
    public function __construct(
        public readonly int $variantId,
        public readonly int $quantity
    ) {
    }
}
