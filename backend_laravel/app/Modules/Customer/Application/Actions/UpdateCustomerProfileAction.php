<?php

declare(strict_types=1);

namespace App\Modules\Customer\Application\Actions;

use App\Modules\Customer\Application\DTOs\UpdateCustomerProfileData;
use App\Modules\Customer\Infrastructure\Persistence\Models\CustomerProfile;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;

class UpdateCustomerProfileAction
{
    public function __construct(
        private readonly GetCustomerProfileAction $getProfile,
    ) {}

    public function execute(User $user, UpdateCustomerProfileData $data): CustomerProfile
    {
        $profile = $this->getProfile->execute($user);

        $attributes = [];

        if ($data->firstName !== null) {
            $attributes['first_name'] = $data->firstName;
        }

        if ($data->lastName !== null) {
            $attributes['last_name'] = $data->lastName;
        }

        if ($data->dateOfBirth !== null) {
            $attributes['date_of_birth'] = $data->dateOfBirth;
        }

        if ($data->gender !== null) {
            $attributes['gender'] = $data->gender;
        }

        if ($data->avatarUrl !== null) {
            $attributes['avatar_url'] = $data->avatarUrl;
        }

        if ($data->preferences !== null) {
            $attributes['preferences'] = $data->preferences;
        }

        if (! empty($attributes)) {
            $profile->update($attributes);
        }

        return $profile->fresh();
    }
}
