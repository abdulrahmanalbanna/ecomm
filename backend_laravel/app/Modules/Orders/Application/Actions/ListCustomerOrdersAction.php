<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Modules\Orders\Application\DTOs\OrderListFiltersData;
use App\Modules\Orders\Domain\Exceptions\OrderNotFoundException;
use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Customer order history — paginated, newest first.
 *
 * Ownership is enforced at the application layer (RLS is not part of this
 * baseline): every query is scoped with where('user_id', $user->id).
 */
class ListCustomerOrdersAction
{
    public function execute(User $user, OrderListFiltersData $filters): LengthAwarePaginator
    {
        $query = Order::forUser($user->id)->with('items');

        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }

        if ($filters->dateFrom !== null) {
            $query->where('placed_at', '>=', $filters->dateFrom);
        }

        if ($filters->dateTo !== null) {
            $query->where('placed_at', '<=', $filters->dateTo);
        }

        return $query->orderByDesc('placed_at')
            ->paginate(perPage: $filters->perPage, page: $filters->page);
    }

    public function get(User $user, string $publicId): Order
    {
        $order = Order::forUser($user->id)
            ->with(['items', 'events' => fn ($q) => $q->orderBy('created_at')])
            ->where('public_id', $publicId)
            ->first();

        if ($order === null) {
            throw OrderNotFoundException::forPublicId($publicId);
        }

        return $order;
    }
}
