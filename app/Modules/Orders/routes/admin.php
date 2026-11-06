<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\Admin\CancellationRequestController;
use App\Modules\Orders\Http\Controllers\Admin\FraudController;
use App\Modules\Orders\Http\Controllers\Admin\OrderController;
use App\Modules\Orders\Http\Controllers\Admin\ReceiptController;
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

    Route::prefix('cancellation-requests')->name('cancellations.')->group(function (): void {
        Route::get('/', [CancellationRequestController::class, 'index'])->middleware('permission:orders.cancel')->name('index');
        Route::post('/{cancellation}/approve', [CancellationRequestController::class, 'approve'])->whereAlphaNumeric('cancellation')->middleware(['permission:orders.cancel', 'throttle:60,1'])->name('approve');
        Route::post('/{cancellation}/reject', [CancellationRequestController::class, 'reject'])->whereAlphaNumeric('cancellation')->middleware(['permission:orders.cancel', 'throttle:60,1'])->name('reject');
    });

    Route::get('/{order}/receipt', [ReceiptController::class, 'show'])->whereAlphaNumeric('order')->middleware(['permission:orders.view', 'throttle:60,1'])->name('receipt');
    Route::get('/{order}', [OrderController::class, 'show'])->middleware('permission:orders.view')->name('show');
    Route::post('/{order}/transition', [OrderController::class, 'transition'])->middleware(['permission:orders.update', 'throttle:60,1'])->name('transition');
    Route::post('/{order}/review/clear', [OrderController::class, 'clearReview'])->middleware(['permission:orders.update', 'throttle:60,1'])->name('review.clear');
    Route::post('/{order}/block', [OrderController::class, 'block'])->middleware(['permission:orders.cancel', 'throttle:60,1'])->name('block');
    Route::post('/{order}/notes', [OrderController::class, 'note'])->middleware(['permission:orders.update', 'throttle:60,1'])->name('note');
});
