<?php

declare(strict_types=1);

use App\Modules\Bookings\Http\Controllers\Admin\BookingController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix. Looking = bookings.view; every change = bookings.manage.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('bookings')->name('bookings.')->group(function (): void {
    Route::get('/', [BookingController::class, 'index'])->middleware('permission:bookings.view')->name('index');
    Route::get('/calendar', [BookingController::class, 'calendar'])->middleware('permission:bookings.view')->name('calendar');
    Route::get('/{booking}', [BookingController::class, 'show'])->whereAlphaNumeric('booking')->middleware('permission:bookings.view')->name('show');
    Route::post('/{booking}/cancel', [BookingController::class, 'cancel'])->whereAlphaNumeric('booking')->middleware(['permission:bookings.manage', 'throttle:bookings-actions'])->name('cancel');
    Route::post('/{booking}/no-show', [BookingController::class, 'noShow'])->whereAlphaNumeric('booking')->middleware(['permission:bookings.manage', 'throttle:bookings-actions'])->name('no-show');
    Route::post('/{booking}/complete', [BookingController::class, 'complete'])->whereAlphaNumeric('booking')->middleware(['permission:bookings.manage', 'throttle:bookings-actions'])->name('complete');
    Route::post('/{booking}/move', [BookingController::class, 'move'])->whereAlphaNumeric('booking')->middleware(['permission:bookings.manage', 'throttle:bookings-actions'])->name('move');
    Route::post('/{booking}/reassign', [BookingController::class, 'reassign'])->whereAlphaNumeric('booking')->middleware(['permission:bookings.manage', 'throttle:bookings-actions'])->name('reassign');
});
