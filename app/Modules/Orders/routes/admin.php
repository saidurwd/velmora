<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\Admin\FraudController;
use App\Modules\Orders\Http\Controllers\Admin\OrderController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('orders')->name('orders.')->group(function (): void {
    Route::get('/', [OrderController::class, 'index'])->middleware('permission:orders.view')->name('index');
    // before /{order}, so "fraud" is never taken for an order
    Route::prefix('fraud')->name('fraud.')->group(function (): void {
        Route::get('/settings', [FraudController::class, 'settings'])->middleware('permission:settings.view')->name('settings');
        Route::put('/settings', [FraudController::class, 'updateSettings'])->middleware(['permission:settings.manage', 'throttle:30,1'])->name('settings.update');
        Route::get('/blocklist', [FraudController::class, 'blocklist'])->middleware('permission:orders.view')->name('blocklist');
        Route::post('/blocklist', [FraudController::class, 'addPhone'])->middleware(['permission:orders.cancel', 'throttle:30,1'])->name('blocklist.add');
        Route::post('/blocklist/{entry}/toggle', [FraudController::class, 'toggle'])->whereNumber('entry')->middleware(['permission:orders.cancel', 'throttle:30,1'])->name('blocklist.toggle');
    });

    Route::get('/{order}', [OrderController::class, 'show'])->middleware('permission:orders.view')->name('show');
    Route::post('/{order}/transition', [OrderController::class, 'transition'])->middleware(['permission:orders.update', 'throttle:60,1'])->name('transition');
    Route::post('/{order}/review/clear', [OrderController::class, 'clearReview'])->middleware(['permission:orders.update', 'throttle:60,1'])->name('review.clear');
    Route::post('/{order}/block', [OrderController::class, 'block'])->middleware(['permission:orders.cancel', 'throttle:60,1'])->name('block');
    Route::post('/{order}/notes', [OrderController::class, 'note'])->middleware(['permission:orders.update', 'throttle:60,1'])->name('note');
});
