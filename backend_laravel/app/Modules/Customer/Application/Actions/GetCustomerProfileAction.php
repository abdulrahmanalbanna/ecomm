<?php

declare(strict_types=1);

namespace App\Modules\Customer\Application\Actions;

use App\Modules\Customer\Infrastructure\Persistence\Models\CustomerProfile;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Retrieve the authenticated customer's profile.
 * Creates a default profile if none exists (lazy initialization).
 */
class GetCustomerProfileAction
{
    public function execute(User $user): CustomerProfile
    {
        $profile = CustomerProfile::where('user_id', $user->id)->first();

        if (! $profile) {
            $profile = DB::transaction(function () use ($user): CustomerProfile {
                return CustomerProfile::create([
                    'user_id'     => $user->id,
                    'first_name'  => '',
                    'last_name'   => '',
                    'preferences' => [],
                ]);
            });
        }

        return $profile;
    }
}
