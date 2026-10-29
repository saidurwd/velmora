<?php

declare(strict_types=1);

use App\Modules\Shipping\Http\Controllers\Admin\MethodController;
use App\Modules\Shipping\Http\Controllers\Admin\ZoneController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('shipping')->name('shipping.')->group(function (): void {
    Route::prefix('zones')->name('zones.')->group(function (): void {
        Route::get('/', [ZoneController::class, 'index'])->middleware('permission:shipping.view')->name('index');
        Route::get('/create', [ZoneController::class, 'create'])->middleware('permission:shipping.manage')->name('create');
        Route::post('/', [ZoneController::class, 'store'])->middleware('permission:shipping.manage')->name('store');
        Route::get('/{zone}/edit', [ZoneController::class, 'edit'])->middleware('permission:shipping.view')->name('edit');
        Route::put('/{zone}', [ZoneController::class, 'update'])->middleware('permission:shipping.manage')->name('update');
    });

    Route::prefix('methods')->name('methods.')->group(function (): void {
        Route::get('/', [MethodController::class, 'index'])->middleware('permission:shipping.view')->name('index');
        Route::get('/create', [MethodController::class, 'create'])->middleware('permission:shipping.manage')->name('create');
        Route::post('/', [MethodController::class, 'store'])->middleware('permission:shipping.manage')->name('store');
        Route::get('/{method}/edit', [MethodController::class, 'edit'])->middleware('permission:shipping.view')->name('edit');
        Route::put('/{method}', [MethodController::class, 'update'])->middleware('permission:shipping.manage')->name('update');
    });
});
