<?php

declare(strict_types=1);

use App\Modules\Shipping\Http\Controllers\Admin\IssueController;
use App\Modules\Shipping\Http\Controllers\Admin\MethodController;
use App\Modules\Shipping\Http\Controllers\Admin\ReturnController;
use App\Modules\Shipping\Http\Controllers\Admin\SettingsController;
use App\Modules\Shipping\Http\Controllers\Admin\ShipmentController;
use App\Modules\Shipping\Http\Controllers\Admin\StatementController;
use App\Modules\Shipping\Http\Controllers\Admin\ZoneController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix. Looking = shipping.view; making, booking, handing over, cancelling and counting goods back = shipping.create_shipment;
// allowing or refusing returns and closing exception items = shipping.manage; importing a courier statement (it records cash) = payments.reconcile; the two delivery rules (return window, delivery attempts) = settings.manage.
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

    Route::get('/shipments', [ShipmentController::class, 'index'])->middleware('permission:shipping.view')->name('shipments.index');
    Route::get('/shipments/{shipment}', [ShipmentController::class, 'show'])->whereAlphaNumeric('shipment')->middleware('permission:shipping.view')->name('shipments.show');
    Route::get('/orders/{order}', [ShipmentController::class, 'order'])->whereAlphaNumeric('order')->middleware('permission:shipping.view')->name('orders.show');
    Route::post('/orders/{order}/shipments', [ShipmentController::class, 'store'])->whereAlphaNumeric('order')->middleware(['permission:shipping.create_shipment', 'throttle:shipping-actions'])->name('shipments.store');
    Route::prefix('shipments/{shipment}')->whereAlphaNumeric('shipment')->middleware(['permission:shipping.create_shipment', 'throttle:shipping-actions'])->name('shipments.')->group(function (): void {
        Route::post('/book', [ShipmentController::class, 'book'])->name('book');
        Route::post('/hand-over', [ShipmentController::class, 'handOver'])->name('hand_over');
        Route::post('/cancel', [ShipmentController::class, 'cancel'])->name('cancel');
        Route::post('/status', [ShipmentController::class, 'status'])->name('status');
    });

    Route::get('/returns', [ReturnController::class, 'index'])->middleware('permission:shipping.view')->name('returns.index');
    Route::get('/returns/{return}', [ReturnController::class, 'show'])->whereAlphaNumeric('return')->middleware('permission:shipping.view')->name('returns.show');
    Route::prefix('returns/{return}')->whereAlphaNumeric('return')->name('returns.')->middleware('throttle:shipping-actions')->group(function (): void {
        Route::post('/authorize', [ReturnController::class, 'authorizeReturn'])->middleware('permission:shipping.manage')->name('authorize');
        Route::post('/reject', [ReturnController::class, 'reject'])->middleware('permission:shipping.manage')->name('reject');
        Route::post('/cancel', [ReturnController::class, 'cancel'])->middleware('permission:shipping.manage')->name('cancel');
        Route::post('/receive', [ReturnController::class, 'receive'])->middleware('permission:shipping.create_shipment')->name('receive');
        Route::post('/inspect', [ReturnController::class, 'inspect'])->middleware('permission:shipping.create_shipment')->name('inspect');
    });

    Route::get('/issues', [IssueController::class, 'index'])->middleware('permission:shipping.view')->name('issues.index');
    Route::post('/issues/{issue}/resolve', [IssueController::class, 'resolve'])->whereAlphaNumeric('issue')->middleware(['permission:shipping.manage', 'throttle:shipping-actions'])->name('issues.resolve');

    Route::get('/settings', [SettingsController::class, 'show'])->middleware('permission:shipping.view')->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->middleware(['permission:settings.manage', 'throttle:shipping-actions'])->name('settings.update');

    Route::get('/statements', [StatementController::class, 'index'])->middleware('permission:shipping.view')->name('statements.index');
    Route::post('/statements/import', [StatementController::class, 'import'])->middleware(['permission:payments.reconcile', 'throttle:shipping-import'])->name('statements.import');
    Route::get('/statements/{statement}', [StatementController::class, 'show'])->whereAlphaNumeric('statement')->middleware('permission:shipping.view')->name('statements.show');
});
