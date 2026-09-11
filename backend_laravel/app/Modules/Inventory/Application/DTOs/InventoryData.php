<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

readonly class InventoryData
{
    public function __construct(
        public int $id,
        public int $variantId,
        public int $quantityOnHand,
        public int $quantityReserved,
        public int $quantityAvailable,
        public int $reorderPoint,
        public int $reorderQuantity,
        public bool $allowBackorder,
        public string $updatedAt,
    ) {
    }
}
