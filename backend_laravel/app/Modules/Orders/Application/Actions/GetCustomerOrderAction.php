<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Modules\Orders\Domain\Exceptions\OrderNotFoundException;
use App\Modules\Orders\Infrastructure\Persistence\Models\Order;

/**
 * Retrieve a single customer order with items (ownership-scoped).
 */
class GetCustomerOrderAction
{
    public function execute(User $user, string $publicId): Order
    {
        $order = Order::forUser($user->id)
            ->with('items')
            ->where('public_id', $publicId)
            ->first();

        if ($order === null) {
            throw OrderNotFoundException::forPublicId($publicId);
        }

        return $order;
    }
}
