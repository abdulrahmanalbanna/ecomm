<?php

declare(strict_types=1);

use App\Modules\Payments\Presentation\Http\Controllers\AdminPaymentController;
use App\Modules\Payments\Presentation\Http\Controllers\CustomerPaymentController;
use App\Modules\Payments\Presentation\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payments module routes (api/v1)
|--------------------------------------------------------------------------
| Customer routes are ownership-scoped at the application layer
| (Payment::forUser — another customer's payment resolves to 404).
|
| Admin routes use the existing RBAC permissions seeded in
| 021_seed_data.sql: payments.view, payments.refund, payments.reconcile.
|
| Webhook routes are PUBLIC by design (gateways cannot hold user tokens);
| security comes from HMAC signature verification inside the processing
| action, performed before anything is persisted.
|
| Lifecycle is POST-only (intents, retries, refunds, settlements); the
| ledger is append-only and exposed via GET — no PUT/PATCH/DELETE.
*/

Route::middleware('auth:api')->prefix('customer')->group(function (): void {
    Route::post('/payments', [CustomerPaymentController::class, 'store']);
    Route::get('/payments', [CustomerPaymentController::class, 'index']);
    Route::get('/payments/{publicId}', [CustomerPaymentController::class, 'show'])
        ->whereUuid('publicId');
    Route::post('/payments/{publicId}/retry', [CustomerPaymentController::class, 'retry'])
        ->whereUuid('publicId');
});

Route::prefix('admin')->group(function (): void {
    Route::middleware(['auth:api', 'permission:payments.view'])->group(function (): void {
        Route::get('/payments', [AdminPaymentController::class, 'index']);
        Route::get('/payments/{publicId}', [AdminPaymentController::class, 'show'])
            ->whereUuid('publicId');
        // Installment plan management is an operational ledger task.
        Route::post('/payments/{publicId}/installments', [AdminPaymentController::class, 'storeInstallmentPlan'])
            ->whereUuid('publicId');
        Route::post('/installments/{id}/settle', [AdminPaymentController::class, 'settleInstallment'])
            ->whereNumber('id');
    });

    Route::middleware(['auth:api', 'permission:payments.refund'])->group(function (): void {
        Route::post('/payments/{publicId}/refunds', [AdminPaymentController::class, 'refund'])
            ->whereUuid('publicId');
    });

    Route::middleware(['auth:api', 'permission:payments.reconcile'])->group(function (): void {
        Route::post('/payments/{publicId}/reconcile', [AdminPaymentController::class, 'reconcile'])
            ->whereUuid('publicId');
    });
});

// Gateway webhooks — no customer auth (signature-verified in the action).
Route::post('/webhooks/tabby', [WebhookController::class, 'tabby']);
Route::post('/webhooks/tamara', [WebhookController::class, 'tamara']);
