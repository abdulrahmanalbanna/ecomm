<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\DTOs;

/**
 * Filter/pagination input for order listing (customer history + admin queue).
 */
final readonly class OrderListFiltersData
{
    public function __construct(
        public ?string $status = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?string $search = null,
        public int $page = 1,
        public int $perPage = 15,
    ) {
    }
}
