<?php

declare(strict_types=1);

use App\Modules\Customer\Presentation\Http\Controllers\CustomerAddressController;
use App\Modules\Customer\Presentation\Http\Controllers\CustomerProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('customer')->group(function (): void {
    // Profile routes
    Route::get('/profile', [CustomerProfileController::class, 'show']);
    Route::put('/profile', [CustomerProfileController::class, 'update']);

    // Address routes
    Route::get('/addresses', [CustomerAddressController::class, 'index']);
    Route::post('/addresses', [CustomerAddressController::class, 'store']);
    Route::get('/addresses/{address}', [CustomerAddressController::class, 'show']);
    Route::put('/addresses/{address}', [CustomerAddressController::class, 'update']);
    Route::delete('/addresses/{address}', [CustomerAddressController::class, 'destroy']);
    Route::patch('/addresses/{address}/default', [CustomerAddressController::class, 'setDefault']);
});
