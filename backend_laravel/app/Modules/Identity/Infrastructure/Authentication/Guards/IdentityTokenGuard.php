<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Authentication\Guards;

use App\Modules\Identity\Infrastructure\Persistence\Models\Session;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * IdentityTokenGuard
 *
 * Custom Laravel Auth Guard that authenticates requests via Bearer tokens
 * matched against SHA-256 hashes stored in the baseline sessions table.
 */
class IdentityTokenGuard implements Guard
{
    protected ?Authenticatable $user = null;
    protected ?Session $currentSession = null;

    public function __construct(
        protected UserProvider $provider,
        protected Request $request
    ) {}

    /**
     * Set the current request instance.
     */
    public function setRequest(Request $request): self
    {
        $this->request = $request;
        $this->user = null;
        $this->currentSession = null;

        return $this;
    }

    /**
     * Determine if the current user is authenticated.
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Determine if the current user is a guest.
     */
    public function guest(): bool
    {
        return ! $this->check();
    }

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?Authenticatable
    {
        $token = $this->getTokenFromRequest();

        if (empty($token)) {
            $this->user = null;
            $this->currentSession = null;
            return null;
        }

        $tokenHash = hash('sha256', $token);

        if ($this->user !== null && $this->currentSession !== null && $this->currentSession->token_hash === $tokenHash) {
            return $this->user;
        }

        /** @var Session|null $session */
        $session = Session::query()
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $session) {
            $this->user = null;
            $this->currentSession = null;
            return null;
        }

        /** @var User|null $user */
        $user = $session->user;

        // Ensure inactive or soft-deleted users cannot authenticate using existing tokens
        if (! $user || ! $user->is_active || $user->trashed()) {
            $this->user = null;
            $this->currentSession = null;
            return null;
        }

        $this->currentSession = $session;
        $this->user = $user;

        return $this->user;
    }

    /**
     * Get the ID for the currently authenticated user.
     */
    public function id(): string|int|null
    {
        if ($user = $this->user()) {
            return $user->getAuthIdentifier();
        }

        return null;
    }

    /**
     * Validate a user's credentials.
     */
    public function validate(array $credentials = []): bool
    {
        if (empty($credentials['email']) || empty($credentials['password'])) {
            return false;
        }

        $user = $this->provider->retrieveByCredentials(['email' => $credentials['email']]);

        if (! $user instanceof User) {
            return false;
        }

        if (! $user->is_active || $user->trashed()) {
            return false;
        }

        return Hash::check($credentials['password'], $user->getAuthPassword());
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    /**
     * Set the current user.
     */
    public function setUser(Authenticatable $user): self
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Get the active session model for the current request.
     */
    public function currentSession(): ?Session
    {
        $this->user();
        return $this->currentSession;
    }

    /**
     * Logout and revoke ONLY the current session.
     */
    public function logout(): void
    {
        $session = $this->currentSession();

        if ($session) {
            $session->revoked_at = now();
            $session->save();
        }

        $this->user = null;
        $this->currentSession = null;
    }

    /**
     * Extract raw Bearer token from the active HTTP request.
     */
    protected function getTokenFromRequest(): ?string
    {
        $request = request();
        $token = $request->bearerToken();

        if (empty($token)) {
            $token = $request->header('X-API-TOKEN');
        }

        return is_string($token) && ! empty($token) ? $token : null;
    }
}
