<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\DTOs;

/**
 * Filter/pagination input for payment listing (customer history + admin).
 */
final readonly class PaymentListFiltersData
{
    public function __construct(
        public ?int $userId = null,
        public ?string $status = null,
        public ?string $gatewayCode = null,
        public ?string $paymentMethod = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public int $page = 1,
        public int $perPage = 15,
    ) {
    }
}
