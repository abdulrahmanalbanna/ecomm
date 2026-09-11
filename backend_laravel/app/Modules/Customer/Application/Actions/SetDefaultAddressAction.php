<?php

declare(strict_types=1);

namespace App\Modules\Customer\Application\Actions;

use App\Modules\Customer\Domain\Exceptions\AddressNotFoundException;
use App\Modules\Customer\Infrastructure\Persistence\Models\Address;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;

class SetDefaultAddressAction
{
    public function __construct(
        private readonly GetAddressAction $getAddress,
    ) {}

    /**
     * Set an address as the default for the authenticated user.
     * PostgreSQL trigger atomically clears previous default.
     *
     * @throws AddressNotFoundException
     */
    public function execute(User $user, int $addressId): Address
    {
        $address = $this->getAddress->execute($user, $addressId);
        $address->update(['is_default' => true]);

        return $address->fresh();
    }
}
