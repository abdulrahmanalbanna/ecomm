<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions;

use App\Modules\Inventory\Application\DTOs\StockReturnData;
use App\Modules\Inventory\Domain\Contracts\InventoryRepositoryContract;
use App\Modules\Inventory\Infrastructure\Persistence\Models\Inventory;
use InvalidArgumentException;

class ReturnStockAction
{
    public function __construct(
        private readonly InventoryRepositoryContract $repository
    ) {
    }

    public function execute(StockReturnData $dto): Inventory
    {
        if ($dto->quantity <= 0) {
            throw new InvalidArgumentException('Return stock quantity must be positive.');
        }

        $this->repository->returnStock(
            $dto->variantId,
            $dto->quantity,
            $dto->orderId,
            $dto->note,
            $dto->createdBy
        );

        return $this->repository->findByVariantId($dto->variantId);
    }
}
