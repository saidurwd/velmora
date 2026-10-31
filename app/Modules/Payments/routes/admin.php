<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\Admin\CodController;
use App\Modules\Payments\Http\Controllers\Admin\PaymentController;
use App\Modules\Payments\Http\Controllers\Admin\ReconciliationController;
use App\Modules\Payments\Http\Controllers\Admin\RefundController;
use App\Modules\Payments\Http\Controllers\Admin\SettingsController;
use App\Modules\Payments\Http\Controllers\Admin\TodayController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix. Viewing = payments.view; refunds = payments.refund; cash, statements, reconciliation, manual
// verify and the freeze switch = payments.reconcile; method and limit settings = settings.manage.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('payments')->name('payments.')->group(function (): void {
    Route::get('/today', [TodayController::class, 'index'])->middleware('permission:payments.view')->name('today');

    Route::get('/refunds', [RefundController::class, 'index'])->middleware('permission:payments.view')->name('refunds.index');
    Route::prefix('refunds/{refund}')->whereAlphaNumeric('refund')->middleware(['permission:payments.refund', 'throttle:payments-actions'])->name('refunds.')->group(function (): void {
        Route::post('/approve', [RefundController::class, 'approve'])->name('approve');
        Route::post('/reject', [RefundController::class, 'reject'])->name('reject');
        Route::post('/send', [RefundController::class, 'send'])->name('send');
        Route::post('/complete', [RefundController::class, 'complete'])->name('complete');
    });

    Route::get('/cod', [CodController::class, 'index'])->middleware('permission:payments.view')->name('cod.index');
    Route::post('/cod/collect', [CodController::class, 'collect'])->middleware(['permission:payments.reconcile', 'throttle:payments-actions'])->name('cod.collect');
    Route::post('/cod/remit', [CodController::class, 'remit'])->middleware(['permission:payments.reconcile', 'throttle:payments-actions'])->name('cod.remit');

    Route::get('/reconciliation', [ReconciliationController::class, 'index'])->middleware('permission:payments.view')->name('reconciliation.index');
    Route::post('/reconciliation/import', [ReconciliationController::class, 'import'])->middleware(['permission:payments.reconcile', 'throttle:payments-import'])->name('reconciliation.import');
    Route::post('/reconciliation/{exception}/resolve', [ReconciliationController::class, 'resolve'])->whereAlphaNumeric('exception')->middleware(['permission:payments.reconcile', 'throttle:payments-actions'])->name('reconciliation.resolve');

    Route::get('/settings', [SettingsController::class, 'show'])->middleware('permission:payments.view')->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->middleware(['permission:settings.manage', 'throttle:payments-actions'])->name('settings.update');
    Route::post('/settings/freeze', [SettingsController::class, 'freeze'])->middleware(['permission:payments.reconcile', 'throttle:payments-actions'])->name('settings.freeze');

    // last, so the fixed pages above are never taken for a payment
    Route::get('/', [PaymentController::class, 'index'])->middleware('permission:payments.view')->name('index');
    Route::get('/{payment}', [PaymentController::class, 'show'])->whereAlphaNumeric('payment')->middleware('permission:payments.view')->name('show');
    Route::post('/{payment}/verify', [PaymentController::class, 'verify'])->whereAlphaNumeric('payment')->middleware(['permission:payments.reconcile', 'throttle:payments-actions'])->name('verify');
    Route::post('/{payment}/refunds', [RefundController::class, 'request'])->whereAlphaNumeric('payment')->middleware(['permission:payments.refund', 'throttle:payments-actions'])->name('refunds.request');
});
