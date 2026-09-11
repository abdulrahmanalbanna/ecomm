<?php

declare(strict_types=1);

use App\Modules\Orders\Presentation\Http\Controllers\AdminOrderController;
use App\Modules\Orders\Presentation\Http\Controllers\CustomerOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Orders module routes (api/v1)
|--------------------------------------------------------------------------
| Customer routes are ownership-scoped at the application layer
| (RLS is not part of this baseline). Admin routes use existing RBAC
| permissions seeded in 021_seed_data.sql.
|
| Lifecycle is POST-only (state transitions); order_events are append-only
| and exposed via GET only — no PUT/PATCH/DELETE for orders or events.
*/

Route::middleware('auth:api')->prefix('customer')->group(function (): void {
    Route::post('/orders/checkout', [CustomerOrderController::class, 'checkout'])
        ->middleware('permission:self.orders.view');
    Route::get('/orders', [CustomerOrderController::class, 'index'])
        ->middleware('permission:self.orders.view');
    Route::get('/orders/{publicId}', [CustomerOrderController::class, 'show'])
        ->middleware('permission:self.orders.view')
        ->whereUuid('publicId');
    Route::post('/orders/{publicId}/cancel', [CustomerOrderController::class, 'cancel'])
        ->middleware('permission:self.orders.view')
        ->whereUuid('publicId');
    Route::get('/orders/{publicId}/events', [CustomerOrderController::class, 'events'])
        ->middleware('permission:self.orders.view')
        ->whereUuid('publicId');
});

Route::prefix('admin')->group(function (): void {
    Route::middleware(['auth:api', 'permission:orders.view'])->group(function (): void {
        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::get('/orders/{publicId}', [AdminOrderController::class, 'show'])
            ->whereUuid('publicId');
        Route::get('/orders/{publicId}/events', [AdminOrderController::class, 'events'])
            ->whereUuid('publicId');
    });

    Route::middleware(['auth:api', 'permission:orders.process'])->group(function (): void {
        Route::post('/orders/{publicId}/transition', [AdminOrderController::class, 'transition'])
            ->whereUuid('publicId');
    });

    Route::middleware(['auth:api', 'permission:orders.cancel'])->group(function (): void {
        Route::post('/orders/{publicId}/cancel', [AdminOrderController::class, 'cancel'])
            ->whereUuid('publicId');
    });
});
