<?php

declare(strict_types=1);

use App\Modules\Catalog\Presentation\Http\Controllers\AttributeAdminController;
use App\Modules\Catalog\Presentation\Http\Controllers\CategoryAdminController;
use App\Modules\Catalog\Presentation\Http\Controllers\CategoryPublicController;
use App\Modules\Catalog\Presentation\Http\Controllers\ProductAdminController;
use App\Modules\Catalog\Presentation\Http\Controllers\ProductPublicController;
use App\Modules\Catalog\Presentation\Http\Controllers\VariantAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Catalog Module API Routes
|--------------------------------------------------------------------------
*/

// Public Catalog Endpoints (No auth required)
Route::get('/catalog/categories', [CategoryPublicController::class, 'index'])->name('catalog.categories.index');
Route::get('/catalog/categories/{slug}', [CategoryPublicController::class, 'show'])->name('catalog.categories.show');
Route::get('/catalog/products', [ProductPublicController::class, 'index'])->name('catalog.products.index');
Route::get('/catalog/products/{publicId}', [ProductPublicController::class, 'show'])->name('catalog.products.show');

// Admin Category Endpoints
Route::middleware(['auth:api', 'permission:categories.view'])->group(function () {
    Route::get('/catalog/admin/categories', [CategoryAdminController::class, 'index'])->name('catalog.admin.categories.index');
    Route::get('/catalog/admin/categories/{id}', [CategoryAdminController::class, 'show'])->name('catalog.admin.categories.show');
});

Route::middleware(['auth:api', 'permission:categories.manage'])->group(function () {
    Route::post('/catalog/admin/categories', [CategoryAdminController::class, 'store'])->name('catalog.admin.categories.store');
    Route::put('/catalog/admin/categories/{id}', [CategoryAdminController::class, 'update'])->name('catalog.admin.categories.update');
    Route::delete('/catalog/admin/categories/{id}', [CategoryAdminController::class, 'destroy'])->name('catalog.admin.categories.destroy');
});

// Admin Product Endpoints
Route::middleware(['auth:api', 'permission:products.view'])->group(function () {
    Route::get('/catalog/admin/products', [ProductAdminController::class, 'index'])->name('catalog.admin.products.index');
    Route::get('/catalog/admin/products/{id}', [ProductAdminController::class, 'show'])->name('catalog.admin.products.show');
});

Route::middleware(['auth:api', 'permission:products.create'])->group(function () {
    Route::post('/catalog/admin/products', [ProductAdminController::class, 'store'])->name('catalog.admin.products.store');
});

Route::middleware(['auth:api', 'permission:products.edit'])->group(function () {
    Route::put('/catalog/admin/products/{id}', [ProductAdminController::class, 'update'])->name('catalog.admin.products.update');
});

Route::middleware(['auth:api', 'permission:products.delete'])->group(function () {
    Route::delete('/catalog/admin/products/{id}', [ProductAdminController::class, 'destroy'])->name('catalog.admin.products.destroy');
});

Route::middleware(['auth:api', 'permission:products.publish'])->group(function () {
    Route::post('/catalog/admin/products/{id}/publish', [ProductAdminController::class, 'publish'])->name('catalog.admin.products.publish');
    Route::post('/catalog/admin/products/{id}/archive', [ProductAdminController::class, 'archive'])->name('catalog.admin.products.archive');
});

// Admin Global Attribute Definitions & Options
Route::middleware(['auth:api', 'permission:products.view'])->group(function () {
    Route::get('/catalog/admin/attributes', [AttributeAdminController::class, 'index'])->name('catalog.admin.attributes.index');
    Route::get('/catalog/admin/attributes/{id}', [AttributeAdminController::class, 'show'])->name('catalog.admin.attributes.show');
});

Route::middleware(['auth:api', 'permission:products.edit'])->group(function () {
    Route::post('/catalog/admin/attributes', [AttributeAdminController::class, 'store'])->name('catalog.admin.attributes.store');
    Route::put('/catalog/admin/attributes/{id}', [AttributeAdminController::class, 'update'])->name('catalog.admin.attributes.update');
    Route::post('/catalog/admin/attributes/{attributeId}/options', [AttributeAdminController::class, 'storeOption'])->name('catalog.admin.attributes.options.store');
    Route::put('/catalog/admin/attributes/{attributeId}/options/{optionId}', [AttributeAdminController::class, 'updateOption'])->name('catalog.admin.attributes.options.update');
    Route::post('/catalog/admin/products/{productId}/attributes', [AttributeAdminController::class, 'assignProductAttribute'])->name('catalog.admin.products.attributes.assign');
    Route::delete('/catalog/admin/products/{productId}/attributes/{attributeId}', [AttributeAdminController::class, 'removeProductAttribute'])->name('catalog.admin.products.attributes.remove');
});

// Admin Product Variants
Route::middleware(['auth:api', 'permission:products.view'])->group(function () {
    Route::get('/catalog/admin/products/{productId}/variants', [VariantAdminController::class, 'index'])->name('catalog.admin.variants.index');
});

Route::middleware(['auth:api', 'permission:products.edit'])->group(function () {
    Route::post('/catalog/admin/products/{productId}/variants', [VariantAdminController::class, 'store'])->name('catalog.admin.variants.store');
    Route::put('/catalog/admin/products/{productId}/variants/{variantId}', [VariantAdminController::class, 'update'])->name('catalog.admin.variants.update');
    Route::delete('/catalog/admin/products/{productId}/variants/{variantId}', [VariantAdminController::class, 'destroy'])->name('catalog.admin.variants.destroy');
});
