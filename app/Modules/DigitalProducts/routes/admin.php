<?php

declare(strict_types=1);

use App\Modules\DigitalProducts\Http\Controllers\Admin\AssetController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix. Looking = digital.view; putting a file in = digital.manage.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('digital')->name('digital.')->group(function (): void {
    Route::get('/assets', [AssetController::class, 'index'])->middleware('permission:digital.view')->name('assets.index');
    Route::get('/assets/{sku}', [AssetController::class, 'show'])->middleware('permission:digital.view')->name('assets.show');
    Route::post('/assets/{sku}/keys', [AssetController::class, 'importKeys'])->middleware(['permission:digital.manage', 'throttle:digital-actions'])->name('assets.keys.import');
    Route::post('/assets/{sku}', [AssetController::class, 'upload'])->middleware(['permission:digital.manage', 'throttle:digital-actions'])->name('assets.upload');
});
