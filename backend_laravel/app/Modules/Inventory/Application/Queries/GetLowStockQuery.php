<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Queries;

use App\Modules\Inventory\Domain\Contracts\InventoryRepositoryContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetLowStockQuery
{
    public function __construct(
        private readonly InventoryRepositoryContract $repository
    ) {
    }

    public function execute(int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getLowStock($perPage);
    }
}
