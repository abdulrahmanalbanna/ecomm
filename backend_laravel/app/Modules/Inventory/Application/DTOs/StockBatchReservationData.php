<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

/**
 * Input for the deadlock-safe batch reservation path
 * (fn_reserve_inventory_batch_v2). Used by multi-item checkout.
 *
 * @see database/sql/018_functions_triggers.sql
 */
final readonly class StockBatchReservationData
{
    /**
     * @param list<array{variant_id: int, quantity: int}> $items Unique variant IDs (DB rejects duplicates)
     */
    public function __construct(
        public array $items,
        public ?int $createdBy = null,
        public ?int $orderId = null,
    ) {
    }
}
