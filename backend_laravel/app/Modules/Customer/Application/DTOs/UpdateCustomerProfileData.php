<?php

declare(strict_types=1);

namespace App\Modules\Customer\Application\DTOs;

/**
 * Immutable DTO for updating customer profile fields.
 */
final class UpdateCustomerProfileData
{
    public function __construct(
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $dateOfBirth,
        public readonly ?string $gender,
        public readonly ?string $avatarUrl,
        public readonly ?array $preferences,
    ) {
    }
}
