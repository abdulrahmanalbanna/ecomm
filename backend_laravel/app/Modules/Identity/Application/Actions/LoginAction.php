<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\DTOs\AuthOutputDTO;
use App\Modules\Identity\Application\DTOs\LoginInputDTO;
use App\Modules\Identity\Domain\Exceptions\AccountInactiveException;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Support\Facades\Hash;

class LoginAction
{
    /**
     * Authenticate user credentials, create a new session, and return raw token once.
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     */
    public function execute(LoginInputDTO $dto): AuthOutputDTO
    {
        /** @var User|null $user */
        $user = User::query()
            ->with(['role', 'customerProfile'])
            ->where('email', strtolower($dto->email))
            ->first();

        if (! $user || ! Hash::check($dto->password, $user->password_hash)) {
            throw new InvalidCredentialsException('Invalid email or password.');
        }

        if ($user->trashed() || ! $user->is_active) {
            throw new AccountInactiveException('Account is inactive or disabled.');
        }

        // Generate cryptographically secure random token (raw token returned ONLY ONCE)
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $ttlDays = (int) config('identity.token_ttl_days', 30);
        $expiresAt = now()->addDays($ttlDays);

        Session::create([
            'user_id' => $user->id,
            'token_hash' => $tokenHash,
            'ip_address' => $dto->ipAddress,
            'user_agent' => $dto->userAgent,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);

        $user->last_login_at = now();
        $user->save();

        return new AuthOutputDTO(
            token: $rawToken,
            tokenType: 'Bearer',
            expiresAt: $expiresAt,
            user: $user
        );
    }
}
