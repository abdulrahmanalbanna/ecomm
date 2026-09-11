<?php

declare(strict_types=1);

use App\Modules\Cart\Presentation\Http\Controllers\CartController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api'])->prefix('cart')->group(function (): void {
    Route::get('/', [CartController::class, 'show']);

    Route::post('/items', [CartController::class, 'addItem']);
    Route::patch('/items/{cartItem}', [CartController::class, 'updateItem']);
    Route::delete('/items/{cartItem}', [CartController::class, 'removeItem']);
    Route::delete('/items', [CartController::class, 'clear']);

    Route::put('/coupon', [CartController::class, 'applyCoupon']);
    Route::delete('/coupon', [CartController::class, 'removeCoupon']);
});
