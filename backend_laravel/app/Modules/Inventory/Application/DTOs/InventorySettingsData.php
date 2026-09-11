<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

readonly class InventorySettingsData
{
    public function __construct(
        public ?int $reorderPoint = null,
        public ?int $reorderQuantity = null,
        public ?bool $allowBackorder = null,
    ) {
    }
}
