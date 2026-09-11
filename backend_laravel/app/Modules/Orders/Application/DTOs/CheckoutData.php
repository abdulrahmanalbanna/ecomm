<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\DTOs;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;

/**
 * Immutable input for CheckoutAction.
 *
 * Prices are NEVER accepted from the client — Catalog is authoritative.
 * discount/shipping/tax are not accepted either (no promotions/shipping
 * modules are in scope); totals are computed server-side.
 */
final readonly class CheckoutData
{
    public function __construct(
        public User $user,
        public int $shippingAddressId,
        public ?int $billingAddressId = null,
        public ?string $notes = null,
        public ?string $ipAddress = null,
    ) {
    }
}
