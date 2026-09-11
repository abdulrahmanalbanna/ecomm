<?php
declare(strict_types=1);

use App\Modules\Identity\Presentation\Http\Controllers\IdentityController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity Module API Routes
|--------------------------------------------------------------------------
|
| Loaded by IdentityServiceProvider under prefix 'api/v1' with 'api' middleware.
|
| Routes:
|   GET  /api/v1/identity/ping
|   POST /api/v1/identity/login
|   POST /api/v1/identity/logout (auth:api)
|   GET  /api/v1/identity/me     (auth:api)
|
*/

// Health check for Identity module routing
Route::get('/identity/ping', static function () {
    return response()->json([
        'module'  => 'Identity',
        'status'  => 'ok',
        'version' => 'v1',
    ]);
})->name('identity.ping');

Route::prefix('identity')->group(function () {
    Route::post('/login', [IdentityController::class, 'login'])->name('identity.login');

    Route::middleware('auth:api')->group(function () {
        Route::post('/logout', [IdentityController::class, 'logout'])->name('identity.logout');
        Route::get('/me', [IdentityController::class, 'me'])->name('identity.me');
        Route::get('/authorization-check', [IdentityController::class, 'authorizationCheck'])->name('identity.authorization-check');
    });
});

