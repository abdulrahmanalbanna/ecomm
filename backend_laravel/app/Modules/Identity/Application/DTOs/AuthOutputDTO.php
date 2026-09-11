<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use DateTimeInterface;

readonly class AuthOutputDTO
{
    public function __construct(
        public string $token,
        public string $tokenType,
        public DateTimeInterface $expiresAt,
        public User $user
    ) {}

    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'token_type' => $this->tokenType,
            'expires_at' => $this->expiresAt->format(DateTimeInterface::ATOM),
        ];
    }
}
