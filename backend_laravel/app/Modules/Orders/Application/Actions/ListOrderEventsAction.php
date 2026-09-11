<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use App\Modules\Orders\Infrastructure\Persistence\Models\OrderEvent;

/**
 * Read the append-only order_events trail through the partition PARENT table
 * (PostgreSQL routes to monthly partitions automatically).
 *
 * Ownership scoping is applied by the caller (customer actions pass the
 * user_id; admin actions do not).
 */
class ListOrderEventsAction
{
    /**
     * @return list<OrderEvent>
     */
    public function execute(Order $order): array
    {
        return OrderEvent::where('order_id', $order->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();
    }
}
