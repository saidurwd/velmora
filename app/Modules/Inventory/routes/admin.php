<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\Admin\StockController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('inventory')->name('inventory.')->group(function (): void {
    Route::get('/', [StockController::class, 'index'])->middleware('permission:inventory.view')->name('index');
    Route::get('/variants/{sku}', [StockController::class, 'show'])->middleware('permission:inventory.view')->name('show');
    Route::post('/variants/{sku}/adjust', [StockController::class, 'adjust'])->middleware('permission:inventory.adjust')->name('adjust');
});
