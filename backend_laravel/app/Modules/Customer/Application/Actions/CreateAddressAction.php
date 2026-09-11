<?php

declare(strict_types=1);

namespace App\Modules\Customer\Application\Actions;

use App\Modules\Customer\Application\DTOs\CreateAddressData;
use App\Modules\Customer\Infrastructure\Persistence\Models\Address;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;

class CreateAddressAction
{
    /**
     * Create a new address for the authenticated user.
     * user_id is always server-controlled.
     */
    public function execute(User $user, CreateAddressData $data): Address
    {
        return Address::create([
            'user_id'        => $user->id,
            'label'          => $data->label,
            'recipient_name' => $data->recipientName,
            'phone'          => $data->phone,
            'line1'          => $data->line1,
            'line2'          => $data->line2,
            'city'           => $data->city,
            'state'          => $data->state,
            'postal_code'    => $data->postalCode,
            'country_code'   => strtoupper($data->countryCode),
            'is_default'     => $data->isDefault,
        ]);
    }
}
