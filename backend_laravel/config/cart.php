<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Shopping Cart Configuration
    |--------------------------------------------------------------------------
    |
    | Defines abandoned cart expiration duration (in days).
    |
    */

    'expiration_days' => (int) env('CART_EXPIRATION_DAYS', 30),
];
