<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\DTOs;

readonly class LoginInputDTO
{
    public function __construct(
        public string $email,
        public string $password,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {}
}
