<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application\DTOs;

final class ApplyCouponData
{
    public function __construct(
        public readonly string $couponCode
    ) {
    }
}
