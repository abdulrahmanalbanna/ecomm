<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions;

use App\Modules\Inventory\Application\DTOs\StockReceiveData;
use App\Modules\Inventory\Domain\Contracts\InventoryRepositoryContract;
use App\Modules\Inventory\Domain\Events\StockReceived;
use App\Modules\Inventory\Infrastructure\Persistence\Models\Inventory;
use InvalidArgumentException;

class ReceiveStockAction
{
    public function __construct(
        private readonly InventoryRepositoryContract $repository
    ) {
    }

    public function execute(StockReceiveData $dto): Inventory
    {
        if ($dto->quantity <= 0) {
            throw new InvalidArgumentException('Receive stock quantity must be positive.');
        }

        $this->repository->receiveStock(
            $dto->variantId,
            $dto->quantity,
            $dto->note,
            $dto->createdBy
        );

        StockReceived::dispatch(
            $dto->variantId,
            $dto->quantity,
            $dto->note,
            $dto->createdBy
        );

        return $this->repository->findByVariantId($dto->variantId);
    }
}
