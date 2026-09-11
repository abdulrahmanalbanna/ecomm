<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Orders\Domain\Exceptions\OrderNotFoundException;
use App\Modules\Orders\Infrastructure\Persistence\Models\Order;

/**
 * Admin order detail (full data: snapshots, addresses, buyer email).
 */
class GetAdminOrderAction
{
    public function execute(string $publicId): Order
    {
        $order = Order::with(['items', 'user', 'events' => fn ($q) => $q->orderBy('created_at')])
            ->where('public_id', $publicId)
            ->first();

        if ($order === null) {
            throw OrderNotFoundException::forPublicId($publicId);
        }

        return $order;
    }
}
