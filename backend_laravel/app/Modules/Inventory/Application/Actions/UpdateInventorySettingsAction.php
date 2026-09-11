<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions;

use App\Modules\Inventory\Application\DTOs\InventorySettingsData;
use App\Modules\Inventory\Domain\Contracts\InventoryRepositoryContract;
use App\Modules\Inventory\Infrastructure\Persistence\Models\Inventory;
use InvalidArgumentException;

class UpdateInventorySettingsAction
{
    public function __construct(
        private readonly InventoryRepositoryContract $repository
    ) {
    }

    public function execute(int $variantId, InventorySettingsData $dto): Inventory
    {
        if ($dto->reorderPoint !== null && $dto->reorderPoint < 0) {
            throw new InvalidArgumentException('Reorder point must be non-negative.');
        }

        if ($dto->reorderQuantity !== null && $dto->reorderQuantity <= 0) {
            throw new InvalidArgumentException('Reorder quantity must be greater than zero.');
        }

        return $this->repository->updateSettings(
            $variantId,
            $dto->reorderPoint,
            $dto->reorderQuantity,
            $dto->allowBackorder
        );
    }
}
