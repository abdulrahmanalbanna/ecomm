<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Queries;

use App\Modules\Inventory\Domain\Contracts\InventoryRepositoryContract;
use App\Modules\Inventory\Domain\Exceptions\InventoryNotFoundException;
use App\Modules\Inventory\Infrastructure\Persistence\Models\Inventory;

class GetInventoryQuery
{
    public function __construct(
        private readonly InventoryRepositoryContract $repository
    ) {
    }

    public function execute(int $variantId): Inventory
    {
        $inventory = $this->repository->findByVariantId($variantId);
        if (! $inventory) {
            throw new InventoryNotFoundException("Inventory record not found for variant ID {$variantId}.");
        }

        return $inventory;
    }
}
