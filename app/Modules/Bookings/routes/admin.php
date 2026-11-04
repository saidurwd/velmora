<?php

declare(strict_types=1);

use App\Modules\Bookings\Http\Controllers\Admin\BookingController;
use App\Modules\Bookings\Http\Controllers\Admin\PolicySetupController;
use App\Modules\Bookings\Http\Controllers\Admin\ResourceSetupController;
use App\Modules\Bookings\Http\Controllers\Admin\ServiceSetupController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix. Looking = bookings.view; every change = bookings.manage.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('bookings')->name('bookings.')->group(function (): void {
    Route::get('/', [BookingController::class, 'index'])->middleware('permission:bookings.view')->name('index');
    Route::get('/calendar', [BookingController::class, 'calendar'])->middleware('permission:bookings.view')->name('calendar');
    // setup: services, people and rooms with their hours, holidays, cancellation policies (before /{booking}, so "setup" is never taken for a booking)
    Route::prefix('setup')->name('setup.')->group(function (): void {
        $manage = ['permission:bookings.manage', 'throttle:bookings-actions'];
        Route::get('/services', [ServiceSetupController::class, 'index'])->middleware('permission:bookings.view')->name('services');
        Route::get('/services/{sku}', [ServiceSetupController::class, 'edit'])->where('sku', '[A-Za-z0-9._-]+')->middleware('permission:bookings.view')->name('services.edit');
        Route::put('/services/{sku}', [ServiceSetupController::class, 'save'])->where('sku', '[A-Za-z0-9._-]+')->middleware($manage)->name('services.save');

        Route::get('/resources', [ResourceSetupController::class, 'index'])->middleware('permission:bookings.view')->name('resources');
        Route::get('/resources/create', [ResourceSetupController::class, 'create'])->middleware('permission:bookings.manage')->name('resources.create');
        Route::post('/resources', [ResourceSetupController::class, 'store'])->middleware($manage)->name('resources.store');
        Route::get('/resources/{resource}', [ResourceSetupController::class, 'edit'])->whereAlphaNumeric('resource')->middleware('permission:bookings.view')->name('resources.edit');
        Route::put('/resources/{resource}', [ResourceSetupController::class, 'update'])->whereAlphaNumeric('resource')->middleware($manage)->name('resources.update');
        Route::put('/resources/{resource}/weekly', [ResourceSetupController::class, 'weekly'])->whereAlphaNumeric('resource')->middleware($manage)->name('resources.weekly');
        Route::post('/resources/{resource}/exceptions', [ResourceSetupController::class, 'exception'])->whereAlphaNumeric('resource')->middleware($manage)->name('resources.exception');
        Route::delete('/resources/{resource}/exceptions/{date}', [ResourceSetupController::class, 'removeException'])->whereAlphaNumeric('resource')->where('date', '[0-9-]+')->middleware($manage)->name('resources.exception.remove');

        Route::get('/holidays', [ResourceSetupController::class, 'holidays'])->middleware('permission:bookings.view')->name('holidays');
        Route::post('/holidays', [ResourceSetupController::class, 'addHoliday'])->middleware($manage)->name('holidays.add');
        Route::delete('/holidays/{date}', [ResourceSetupController::class, 'removeHoliday'])->where('date', '[0-9-]+')->middleware($manage)->name('holidays.remove');

        Route::get('/policies', [PolicySetupController::class, 'index'])->middleware('permission:bookings.view')->name('policies');
        Route::get('/policies/create', [PolicySetupController::class, 'create'])->middleware('permission:bookings.manage')->name('policies.create');
        Route::post('/policies', [PolicySetupController::class, 'store'])->middleware($manage)->name('policies.store');
        Route::get('/policies/{policy}', [PolicySetupController::class, 'edit'])->whereAlphaNumeric('policy')->middleware('permission:bookings.view')->name('policies.edit');
        Route::put('/policies/{policy}', [PolicySetupController::class, 'update'])->whereAlphaNumeric('policy')->middleware($manage)->name('policies.update');
    });

    Route::get('/{booking}', [BookingController::class, 'show'])->whereAlphaNumeric('booking')->middleware('permission:bookings.view')->name('show');
    Route::post('/{booking}/cancel', [BookingController::class, 'cancel'])->whereAlphaNumeric('booking')->middleware(['permission:bookings.manage', 'throttle:bookings-actions'])->name('cancel');
    Route::post('/{booking}/no-show', [BookingController::class, 'noShow'])->whereAlphaNumeric('booking')->middleware(['permission:bookings.manage', 'throttle:bookings-actions'])->name('no-show');
    Route::post('/{booking}/complete', [BookingController::class, 'complete'])->whereAlphaNumeric('booking')->middleware(['permission:bookings.manage', 'throttle:bookings-actions'])->name('complete');
    Route::post('/{booking}/move', [BookingController::class, 'move'])->whereAlphaNumeric('booking')->middleware(['permission:bookings.manage', 'throttle:bookings-actions'])->name('move');
    Route::post('/{booking}/reassign', [BookingController::class, 'reassign'])->whereAlphaNumeric('booking')->middleware(['permission:bookings.manage', 'throttle:bookings-actions'])->name('reassign');
});
