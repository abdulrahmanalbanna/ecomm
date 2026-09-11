<?php

declare(strict_types=1);

namespace App\Modules\Customer\Application\Actions;

use App\Modules\Customer\Application\DTOs\UpdateAddressData;
use App\Modules\Customer\Domain\Exceptions\AddressNotFoundException;
use App\Modules\Customer\Infrastructure\Persistence\Models\Address;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;

class UpdateAddressAction
{
    public function __construct(
        private readonly GetAddressAction $getAddress,
    ) {}

    /**
     * Update an existing address owned by the authenticated user.
     *
     * @throws AddressNotFoundException
     */
    public function execute(User $user, int $addressId, UpdateAddressData $data): Address
    {
        $address = $this->getAddress->execute($user, $addressId);

        $attributes = [];

        if ($data->label !== null) {
            $attributes['label'] = $data->label;
        }

        if ($data->recipientName !== null) {
            $attributes['recipient_name'] = $data->recipientName;
        }

        if ($data->phone !== null) {
            $attributes['phone'] = $data->phone;
        }

        if ($data->line1 !== null) {
            $attributes['line1'] = $data->line1;
        }

        if ($data->line2 !== null) {
            $attributes['line2'] = $data->line2;
        }

        if ($data->city !== null) {
            $attributes['city'] = $data->city;
        }

        if ($data->state !== null) {
            $attributes['state'] = $data->state;
        }

        if ($data->postalCode !== null) {
            $attributes['postal_code'] = $data->postalCode;
        }

        if ($data->countryCode !== null) {
            $attributes['country_code'] = strtoupper($data->countryCode);
        }

        if ($data->isDefault !== null) {
            $attributes['is_default'] = $data->isDefault;
        }

        if (! empty($attributes)) {
            $address->update($attributes);
        }

        return $address->fresh();
    }
}
