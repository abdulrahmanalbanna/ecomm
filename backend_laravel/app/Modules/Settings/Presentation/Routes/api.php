<?php

declare(strict_types=1);

use App\Modules\Settings\Presentation\Http\Controllers\SettingsPublicController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Settings Module API Routes
|--------------------------------------------------------------------------
|
| Public storefront configuration. No auth required — the service layer only
| ever returns whitelisted public + active keys (see SettingsService).
|
*/

Route::get('/settings', [SettingsPublicController::class, 'index'])->name('settings.index');
Route::get('/homepage', [SettingsPublicController::class, 'homepage'])->name('homepage.index');
Route::get('/settings/{key}', [SettingsPublicController::class, 'show'])
    ->where('key', '[A-Za-z0-9_.]+')
    ->name('settings.show');
