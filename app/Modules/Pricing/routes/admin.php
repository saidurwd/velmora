<?php

declare(strict_types=1);

use App\Modules\Pricing\Http\Controllers\Admin\BulkPriceController;
use App\Modules\Pricing\Http\Controllers\Admin\CouponController;
use App\Modules\Pricing\Http\Controllers\Admin\PriceController;
use App\Modules\Pricing\Http\Controllers\Admin\PromotionController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('pricing')->name('pricing.')->group(function (): void {
    Route::get('/', [PriceController::class, 'index'])->middleware('permission:pricing.view')->name('index');

    Route::get('/bulk', [BulkPriceController::class, 'form'])->middleware('permission:pricing.update')->name('bulk');
    Route::post('/bulk/preview', [BulkPriceController::class, 'preview'])->middleware('permission:pricing.update')->name('bulk.preview');
    Route::post('/bulk/apply', [BulkPriceController::class, 'apply'])->middleware('permission:pricing.update')->name('bulk.apply');

    Route::get('/variants/{sku}', [PriceController::class, 'show'])->middleware('permission:pricing.view')->name('show');
    Route::put('/variants/{sku}', [PriceController::class, 'update'])->middleware('permission:pricing.update')->name('update');
    Route::post('/variants/{sku}/sales', [PriceController::class, 'schedule'])->middleware('permission:pricing.update')->name('sales.store');
    Route::delete('/variants/{sku}/sales/{sale}', [PriceController::class, 'cancelSale'])->whereNumber('sale')->middleware('permission:pricing.update')->name('sales.destroy');
});

// Coupons and automatic promotions: /admin/promotions/coupons, /admin/promotions/promotions
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('promotions')->name('promotions.')->group(function (): void {
    Route::prefix('coupons')->name('coupons.')->group(function (): void {
        Route::get('/', [CouponController::class, 'index'])->middleware('permission:promotions.view')->name('index');
        Route::get('/create', [CouponController::class, 'create'])->middleware('permission:promotions.manage')->name('create');
        Route::post('/', [CouponController::class, 'store'])->middleware('permission:promotions.manage')->name('store');
        Route::get('/{coupon}', [CouponController::class, 'show'])->middleware('permission:promotions.view')->name('show');
        Route::get('/{coupon}/edit', [CouponController::class, 'edit'])->middleware('permission:promotions.view')->name('edit');
        Route::put('/{coupon}', [CouponController::class, 'update'])->middleware('permission:promotions.manage')->name('update');
    });

    Route::prefix('automatic')->name('promotions.')->group(function (): void {
        Route::get('/', [PromotionController::class, 'index'])->middleware('permission:promotions.view')->name('index');
        Route::get('/create', [PromotionController::class, 'create'])->middleware('permission:promotions.manage')->name('create');
        Route::post('/', [PromotionController::class, 'store'])->middleware('permission:promotions.manage')->name('store');
        Route::get('/{promotion}/edit', [PromotionController::class, 'edit'])->middleware('permission:promotions.view')->name('edit');
        Route::put('/{promotion}', [PromotionController::class, 'update'])->middleware('permission:promotions.manage')->name('update');
    });
});
