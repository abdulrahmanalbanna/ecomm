<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Queries;

use App\Modules\Inventory\Domain\Contracts\InventoryRepositoryContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetInventoryMovementsQuery
{
    public function __construct(
        private readonly InventoryRepositoryContract $repository
    ) {
    }

    public function execute(int $variantId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getMovements($variantId, $perPage);
    }
}
