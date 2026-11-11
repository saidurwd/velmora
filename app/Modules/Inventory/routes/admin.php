<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\Admin\CountController;
use App\Modules\Inventory\Http\Controllers\Admin\ReportController;
use App\Modules\Inventory\Http\Controllers\Admin\StockController;
use App\Modules\Inventory\Http\Controllers\Admin\StockImportController;
use App\Modules\Inventory\Http\Controllers\Admin\TransferController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix. Looking and the reports = inventory.view; transfers = inventory.transfer; opening, counting and handing in a stock-take =
// inventory.count; approving or rejecting one (it changes stock, and must be a different person from who handed it in) = inventory.adjust.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('inventory')->name('inventory.')->group(function (): void {
    Route::get('/', [StockController::class, 'index'])->middleware('permission:inventory.view')->name('index');
    Route::get('/variants/{sku}', [StockController::class, 'show'])->middleware('permission:inventory.view')->name('show');
    Route::post('/variants/{sku}/adjust', [StockController::class, 'adjust'])->middleware('permission:inventory.adjust')->name('adjust');

    Route::get('/transfers', [TransferController::class, 'index'])->middleware('permission:inventory.view')->name('transfers.index');
    Route::get('/transfers/create', [TransferController::class, 'create'])->middleware('permission:inventory.transfer')->name('transfers.create');
    Route::post('/transfers', [TransferController::class, 'store'])->middleware(['permission:inventory.transfer', 'throttle:inventory-actions'])->name('transfers.store');
    Route::get('/transfers/{transfer}', [TransferController::class, 'show'])->whereAlphaNumeric('transfer')->middleware('permission:inventory.view')->name('transfers.show');
    Route::post('/transfers/{transfer}/receive', [TransferController::class, 'receive'])->whereAlphaNumeric('transfer')->middleware(['permission:inventory.transfer', 'throttle:inventory-actions'])->name('transfers.receive');
    Route::post('/transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->whereAlphaNumeric('transfer')->middleware(['permission:inventory.transfer', 'throttle:inventory-actions'])->name('transfers.cancel');

    Route::get('/counts', [CountController::class, 'index'])->middleware('permission:inventory.view')->name('counts.index');
    Route::post('/counts', [CountController::class, 'open'])->middleware(['permission:inventory.count', 'throttle:inventory-actions'])->name('counts.open');
    Route::get('/counts/{count}', [CountController::class, 'show'])->whereAlphaNumeric('count')->middleware('permission:inventory.view')->name('counts.show');
    Route::prefix('counts/{count}')->whereAlphaNumeric('count')->middleware('throttle:inventory-actions')->name('counts.')->group(function (): void {
        Route::post('/record', [CountController::class, 'record'])->middleware('permission:inventory.count')->name('record');
        Route::post('/submit', [CountController::class, 'submit'])->middleware('permission:inventory.count')->name('submit');
        Route::post('/cancel', [CountController::class, 'cancel'])->middleware('permission:inventory.count')->name('cancel');
        Route::post('/approve', [CountController::class, 'approve'])->middleware('permission:inventory.adjust')->name('approve');
        Route::post('/reject', [CountController::class, 'reject'])->middleware('permission:inventory.adjust')->name('reject');
    });

    Route::prefix('imports')->name('imports.')->middleware(['permission:inventory.adjust', 'throttle:inventory-actions'])->group(function (): void {
        Route::get('/', [StockImportController::class, 'index'])->name('index');
        Route::post('/', [StockImportController::class, 'store'])->name('store');
        Route::get('/{import}', [StockImportController::class, 'show'])->whereAlphaNumeric('import')->name('show');
        Route::get('/{import}/problems', [StockImportController::class, 'errors'])->whereAlphaNumeric('import')->name('errors');
        Route::post('/{import}/apply', [StockImportController::class, 'apply'])->whereAlphaNumeric('import')->name('apply');
        Route::post('/{import}/rollback', [StockImportController::class, 'rollback'])->whereAlphaNumeric('import')->name('rollback');
    });

    Route::get('/reports/low-stock', [ReportController::class, 'low'])->middleware('permission:inventory.view')->name('reports.low');
    Route::get('/reports/aging', [ReportController::class, 'aging'])->middleware('permission:inventory.view')->name('reports.aging');
});
