<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payments module configuration
|--------------------------------------------------------------------------
|
| SECRETS POLICY (Task 10, §33/§36):
|   - API keys, secrets and webhook signing secrets live ONLY here,
|     sourced from environment variables (never in the database, never
|     committed with real values).
|   - payment_gateways.config (database) holds NON-SENSITIVE config only
|     (api_version, webhook_path, installment_options, checkout_url...).
|   - Tests use fake values via Http::fake() and env overrides.
|
*/

return [

    'currency' => env('PAYMENTS_CURRENCY', 'SAR'),

    'gateways' => [

        'tabby' => [
            'api_key'        => env('TABBY_API_KEY', ''),
            'api_secret'     => env('TABBY_API_SECRET', ''),
            'webhook_secret' => env('TABBY_WEBHOOK_SECRET', ''),
            'base_url'       => env('TABBY_BASE_URL', 'https://api.tabby.ai/api/v2'),
            'merchant_code'  => env('TABBY_MERCHANT_CODE', 'TEST-CommerceSingleVendor'),
            'webhook_header' => 't-signature',
            'timeout'        => (int) env('TABBY_TIMEOUT', 15),
        ],

        'tamara' => [
            'api_key'        => env('TAMARA_API_KEY', ''),
            'api_secret'     => env('TAMARA_API_SECRET', ''),
            'webhook_secret' => env('TAMARA_WEBHOOK_SECRET', ''),
            'base_url'       => env('TAMARA_BASE_URL', 'https://api.tamara.co'),
            'merchant_code'  => env('TAMARA_MERCHANT_CODE', 'commerce-single-vendor'),
            'webhook_header' => 'x-tamara-signature',
            'timeout'        => (int) env('TAMARA_TIMEOUT', 15),
        ],

    ],

];
