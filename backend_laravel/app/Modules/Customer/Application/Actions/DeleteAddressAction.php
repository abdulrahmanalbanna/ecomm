<?php

declare(strict_types=1);

namespace App\Modules\Customer\Application\Actions;

use App\Modules\Customer\Domain\Exceptions\AddressNotFoundException;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;

class DeleteAddressAction
{
    public function __construct(
        private readonly GetAddressAction $getAddress,
    ) {}

    /**
     * Delete an address owned by the authenticated user.
     * Hard delete — baseline schema has no deleted_at column.
     *
     * @throws AddressNotFoundException
     */
    public function execute(User $user, int $addressId): void
    {
        $address = $this->getAddress->execute($user, $addressId);
        $address->delete();
    }
}
