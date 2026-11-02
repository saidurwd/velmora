<?php

declare(strict_types=1);

use App\Modules\DigitalProducts\Http\Controllers\Admin\AssetController;
use App\Modules\DigitalProducts\Http\Controllers\Admin\EntitlementController;
use App\Modules\DigitalProducts\Http\Controllers\Admin\PolicyController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix. Looking = digital.view; changing anything (files, keys, refund policies, a customer's time and downloads, revoking) = digital.manage.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('digital')->name('digital.')->group(function (): void {
    Route::get('/assets', [AssetController::class, 'index'])->middleware('permission:digital.view')->name('assets.index');
    Route::get('/assets/{sku}', [AssetController::class, 'show'])->middleware('permission:digital.view')->name('assets.show');
    Route::post('/assets/{sku}/policy', [AssetController::class, 'setPolicy'])->middleware(['permission:digital.manage', 'throttle:digital-actions'])->name('assets.policy');

    Route::get('/policies', [PolicyController::class, 'index'])->middleware('permission:digital.view')->name('policies.index');
    Route::get('/policies/create', [PolicyController::class, 'create'])->middleware('permission:digital.manage')->name('policies.create');
    Route::post('/policies', [PolicyController::class, 'store'])->middleware(['permission:digital.manage', 'throttle:digital-actions'])->name('policies.store');
    Route::get('/policies/{policy}/edit', [PolicyController::class, 'edit'])->whereAlphaNumeric('policy')->middleware('permission:digital.view')->name('policies.edit');
    Route::put('/policies/{policy}', [PolicyController::class, 'update'])->whereAlphaNumeric('policy')->middleware(['permission:digital.manage', 'throttle:digital-actions'])->name('policies.update');

    Route::get('/entitlements', [EntitlementController::class, 'index'])->middleware('permission:digital.view')->name('entitlements.index');
    Route::post('/entitlements/{entitlement}/adjust', [EntitlementController::class, 'adjust'])->whereAlphaNumeric('entitlement')->middleware(['permission:digital.manage', 'throttle:digital-actions'])->name('entitlements.adjust');
    Route::post('/entitlements/{entitlement}/revoke', [EntitlementController::class, 'revoke'])->whereAlphaNumeric('entitlement')->middleware(['permission:digital.manage', 'throttle:digital-actions'])->name('entitlements.revoke');

    Route::post('/assets/{sku}/keys', [AssetController::class, 'importKeys'])->middleware(['permission:digital.manage', 'throttle:digital-actions'])->name('assets.keys.import');
    Route::post('/assets/{sku}', [AssetController::class, 'upload'])->middleware(['permission:digital.manage', 'throttle:digital-actions'])->name('assets.upload');
});
