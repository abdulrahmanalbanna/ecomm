<?php

declare(strict_types=1);

namespace App\Modules\Customer\Application\Actions;

use App\Modules\Customer\Domain\Exceptions\AddressNotFoundException;
use App\Modules\Customer\Infrastructure\Persistence\Models\Address;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;

class GetAddressAction
{
    /**
     * Retrieve a single address owned by the authenticated user.
     * Ownership-scoped: never loads addresses belonging to other users.
     *
     * @throws AddressNotFoundException
     */
    public function execute(User $user, int $addressId): Address
    {
        $address = Address::where('user_id', $user->id)
            ->where('id', $addressId)
            ->first();

        if (! $address) {
            throw new AddressNotFoundException('Address not found.');
        }

        return $address;
    }
}
