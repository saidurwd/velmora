<?php

declare(strict_types=1);

use App\Modules\Pricing\Http\Controllers\Admin\BulkPriceController;
use App\Modules\Pricing\Http\Controllers\Admin\PriceController;
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
