<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes Entry Point
|--------------------------------------------------------------------------
|
| This file is the Laravel API route entry point, registered in
| bootstrap/app.php via withRouting(api: ...).
|
| IMPORTANT: Do NOT add module-specific routes here.
| Each module loads its own routes via its ServiceProvider.
| See: app/Modules/{Module}/Providers/{Module}ServiceProvider.php
|
| This file should only contain:
|   - Global API health/status routes
|   - Any truly cross-module API middleware setup
|
*/

// Global API health check endpoint.
// Returns application status without requiring database connectivity.
Route::get('/health', static function () {
    return response()->json([
        'status'      => 'ok',
        'application' => config('app.name'),
        'environment' => config('app.env'),
        'timestamp'   => now()->toIso8601String(),
    ]);
})->name('api.health');
