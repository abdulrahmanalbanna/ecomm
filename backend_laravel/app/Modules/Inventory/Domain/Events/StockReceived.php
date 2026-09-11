<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $variantId,
        public readonly int $quantity,
        public readonly ?string $note = null,
        public readonly ?int $actorId = null
    ) {
    }
}
