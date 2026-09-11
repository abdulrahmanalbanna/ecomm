<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Orders\Application\DTOs\OrderListFiltersData;
use App\Modules\Orders\Domain\Exceptions\OrderNotFoundException;
use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Admin order queue — no ownership scoping (RBAC permission orders.view).
 */
class ListAdminOrdersAction
{
    public function execute(OrderListFiltersData $filters): LengthAwarePaginator
    {
        $query = Order::query()->with('items');

        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }

        if ($filters->dateFrom !== null) {
            $query->where('placed_at', '>=', $filters->dateFrom);
        }

        if ($filters->dateTo !== null) {
            $query->where('placed_at', '<=', $filters->dateTo);
        }

        if ($filters->search !== null && trim($filters->search) !== '') {
            $term = '%'.strtolower(trim($filters->search)).'%';

            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(public_id::text) LIKE ?', [$term])
                    ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(email) LIKE ?', [$term]))
                    ->orWhereHas('items', fn ($i) => $i->whereRaw('LOWER(sku) LIKE ?', [$term]));
            });
        }

        return $query->orderByDesc('placed_at')
            ->paginate(perPage: $filters->perPage, page: $filters->page);
    }

    public function get(string $publicId): Order
    {
        $order = Order::with(['items', 'user'])
            ->where('public_id', $publicId)
            ->first();

        if ($order === null) {
            throw OrderNotFoundException::forPublicId($publicId);
        }

        return $order;
    }
}
