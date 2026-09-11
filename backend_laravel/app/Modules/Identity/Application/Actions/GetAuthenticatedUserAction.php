<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;

class GetAuthenticatedUserAction
{
    /**
     * Resolve public user identity details.
     */
    public function execute(User $user): User
    {
        $user->loadMissing(['role', 'customerProfile']);
        return $user;
    }
}
