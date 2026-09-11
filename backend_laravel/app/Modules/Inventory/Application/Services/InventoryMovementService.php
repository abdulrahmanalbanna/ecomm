<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Infrastructure\Persistence\Models\InventoryMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class InventoryMovementService
{
    /**
     * Get movement history for a specific product variant with filtering.
     */
    public function getHistoryForVariant(int $variantId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $filters['variant_id'] = $variantId;

        return $this->getAllHistory($filters, $perPage);
    }

    /**
     * Query movement history across the partitioned inventory_movements parent table.
     */
    public function getAllHistory(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryMovement::with(['variant', 'createdBy']);

        if (! empty($filters['variant_id'])) {
            $query->where('variant_id', (int) $filters['variant_id']);
        }

        if (! empty($filters['movement_type'])) {
            $query->where('movement_type', (string) $filters['movement_type']);
        }

        if (! empty($filters['order_id'])) {
            $query->where('order_id', (int) $filters['order_id']);
        }

        if (! empty($filters['created_by'])) {
            $query->where('created_by', (int) $filters['created_by']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }
}
