<?php

declare(strict_types=1);

namespace App\Modules\Customer\Application\DTOs;

/**
 * Immutable DTO for creating a new customer address.
 */
final class CreateAddressData
{
    public function __construct(
        public readonly ?string $label,
        public readonly string $recipientName,
        public readonly ?string $phone,
        public readonly string $line1,
        public readonly ?string $line2,
        public readonly string $city,
        public readonly ?string $state,
        public readonly ?string $postalCode,
        public readonly string $countryCode,
        public readonly bool $isDefault = false,
    ) {
    }
}
