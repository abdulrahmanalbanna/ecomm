<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockAdjusted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $variantId,
        public readonly int $quantityDelta,
        public readonly ?string $note = null,
        public readonly ?int $actorId = null
    ) {
    }
}
