<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions;

use App\Modules\Inventory\Application\DTOs\StockAdjustmentData;
use App\Modules\Inventory\Domain\Contracts\InventoryRepositoryContract;
use App\Modules\Inventory\Domain\Events\StockAdjusted;
use App\Modules\Inventory\Infrastructure\Persistence\Models\Inventory;
use InvalidArgumentException;

class AdjustStockAction
{
    public function __construct(
        private readonly InventoryRepositoryContract $repository
    ) {
    }

    public function execute(StockAdjustmentData $dto): Inventory
    {
        if ($dto->quantityDelta === 0) {
            throw new InvalidArgumentException('Stock adjustment quantity delta cannot be zero.');
        }

        $this->repository->adjustStock(
            $dto->variantId,
            $dto->quantityDelta,
            $dto->note,
            $dto->createdBy
        );

        StockAdjusted::dispatch(
            $dto->variantId,
            $dto->quantityDelta,
            $dto->note,
            $dto->createdBy
        );

        return $this->repository->findByVariantId($dto->variantId);
    }
}
