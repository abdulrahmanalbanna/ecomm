<?php

declare(strict_types=1);

use App\Modules\Inventory\Presentation\Http\Controllers\InventoryAdminController;
use App\Modules\Inventory\Presentation\Http\Controllers\InventoryMovementAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory Module API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api', 'permission:inventory.view'])->group(function () {
    Route::get('/admin/inventory', [InventoryAdminController::class, 'index'])->name('inventory.admin.index');
    Route::get('/admin/inventory/low-stock', [InventoryAdminController::class, 'lowStock'])->name('inventory.admin.low-stock');
    Route::get('/admin/inventory/movements', [InventoryMovementAdminController::class, 'index'])->name('inventory.admin.movements.index');
    Route::get('/admin/inventory/variants/{variant}', [InventoryAdminController::class, 'show'])->name('inventory.admin.show');
    Route::get('/admin/inventory/variants/{variant}/movements', [InventoryMovementAdminController::class, 'variantHistory'])->name('inventory.admin.variants.movements');
});

Route::middleware(['auth:api', 'permission:inventory.adjust'])->group(function () {
    Route::patch('/admin/inventory/variants/{variant}/settings', [InventoryAdminController::class, 'updateSettings'])->name('inventory.admin.settings.update');
    Route::post('/admin/inventory/variants/{variant}/receive', [InventoryAdminController::class, 'receive'])->name('inventory.admin.receive');
    Route::post('/admin/inventory/variants/{variant}/adjust', [InventoryAdminController::class, 'adjust'])->name('inventory.admin.adjust');
    Route::post('/admin/inventory/variants/{variant}/reserve', [InventoryAdminController::class, 'reserve'])->name('inventory.admin.reserve');
    Route::post('/admin/inventory/variants/{variant}/release', [InventoryAdminController::class, 'release'])->name('inventory.admin.release');
    Route::post('/admin/inventory/variants/{variant}/deduct', [InventoryAdminController::class, 'deduct'])->name('inventory.admin.deduct');
    Route::post('/admin/inventory/variants/{variant}/return', [InventoryAdminController::class, 'return'])->name('inventory.admin.return');
});
