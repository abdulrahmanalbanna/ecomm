<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Handles preflight (OPTIONS) and actual requests from the Next.js
    | storefront (http://localhost:3000) to the Laravel API
    | (http://localhost:8000/api/*). Handled globally by
    | Illuminate\Http\Middleware\HandleCors.
    |
    | NOTE: A missing Access-Control-Allow-Origin header on
    | GET /api/v1/settings was previously a *symptom* of a 500 in
    | SettingsService (BusinessSetting `value` array-cast). Exception
    | responses bypass HandleCors, so always fix the 500 first, then
    | verify CORS on a 200 response with an Origin header.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_unique(array_merge(
        ['http://localhost:3000', 'http://127.0.0.1:3000'],
        array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
        array_filter([env('FRONTEND_URL'), env('NEXT_PUBLIC_SITE_URL')]),
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
