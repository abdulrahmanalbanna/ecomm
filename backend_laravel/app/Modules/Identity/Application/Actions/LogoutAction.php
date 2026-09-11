<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Infrastructure\Authentication\Guards\IdentityTokenGuard;
use Illuminate\Contracts\Auth\Guard;

class LogoutAction
{
    /**
     * Revoke strictly the current session token.
     */
    public function execute(Guard $guard): void
    {
        if ($guard instanceof IdentityTokenGuard) {
            $guard->logout();
        }
    }
}
