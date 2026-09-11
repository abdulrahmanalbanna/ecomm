<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

readonly class StockReceiveData
{
    public function __construct(
        public int $variantId,
        public int $quantity,
        public ?string $note = null,
        public ?int $createdBy = null,
    ) {
    }
}
