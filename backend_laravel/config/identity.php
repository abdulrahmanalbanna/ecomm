<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Identity Module Configuration
    |--------------------------------------------------------------------------
    |
    | Defines token expiration TTL and authentication settings.
    |
    */

    'token_ttl_days' => (int) env('IDENTITY_TOKEN_TTL_DAYS', 30),
];
