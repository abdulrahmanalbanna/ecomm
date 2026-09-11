<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

readonly class StockAdjustmentData
{
    public function __construct(
        public int $variantId,
        public int $quantityDelta,
        public ?string $note = null,
        public ?int $createdBy = null,
    ) {
    }
}
