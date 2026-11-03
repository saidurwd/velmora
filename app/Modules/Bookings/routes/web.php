<?php

declare(strict_types=1);

use App\Modules\Bookings\Http\Controllers\Web\BookController;
use App\Modules\Bookings\Http\Controllers\Web\GuestBookingsController;
use App\Modules\Bookings\Http\Controllers\Web\MyBookingsController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    // choosing a time for a service: anyone may look at the free times; pressing one holds it and puts the booking in their own cart
    Route::get('/book/{sku}', [BookController::class, 'show'])->where('sku', '[A-Za-z0-9._-]+')->middleware('throttle:public-read')->name('bookings.book');
    Route::post('/book/{sku}/hold', [BookController::class, 'hold'])->where('sku', '[A-Za-z0-9._-]+')->middleware('throttle:booking-hold')->name('bookings.hold');

    // "My bookings": a signed-in customer's own bookings only
    Route::middleware(['auth:web', 'session.policy:web'])->group(function (): void {
        Route::get('/account/bookings', [MyBookingsController::class, 'index'])->name('bookings.mine');
        Route::get('/account/bookings/{booking}', [MyBookingsController::class, 'show'])->whereAlphaNumeric('booking')->name('bookings.mine.show');
        Route::post('/account/bookings/{booking}/cancel', [MyBookingsController::class, 'cancel'])->whereAlphaNumeric('booking')->middleware('throttle:booking-manage')->name('bookings.mine.cancel');
        Route::post('/account/bookings/{booking}/move', [MyBookingsController::class, 'move'])->whereAlphaNumeric('booking')->middleware('throttle:booking-manage')->name('bookings.mine.move');
        Route::post('/account/bookings/{booking}/complete', [MyBookingsController::class, 'complete'])->whereAlphaNumeric('booking')->middleware('throttle:booking-manage')->name('bookings.mine.complete');
    });

    // a guest's bookings: a link we signed, a code sent to the order's phone, then a short session for that one order
    Route::get('/orders/{order}/bookings', [GuestBookingsController::class, 'index'])->whereAlphaNumeric('order')->middleware(['signed', 'throttle:public-read'])->name('bookings.guest');
    Route::post('/orders/{order}/bookings/code', [GuestBookingsController::class, 'sendCode'])->whereAlphaNumeric('order')->middleware(['signed', 'throttle:otp'])->name('bookings.guest.code');
    Route::post('/orders/{order}/bookings/verify', [GuestBookingsController::class, 'verify'])->whereAlphaNumeric('order')->middleware(['signed', 'throttle:otp'])->name('bookings.guest.verify');
    Route::get('/orders/{order}/bookings/{booking}', [GuestBookingsController::class, 'show'])->whereAlphaNumeric('order')->whereAlphaNumeric('booking')->middleware('throttle:public-read')->name('bookings.guest.show');
    Route::post('/orders/{order}/bookings/{booking}/cancel', [GuestBookingsController::class, 'cancel'])->whereAlphaNumeric('order')->whereAlphaNumeric('booking')->middleware('throttle:booking-manage')->name('bookings.guest.cancel');
    Route::post('/orders/{order}/bookings/{booking}/move', [GuestBookingsController::class, 'move'])->whereAlphaNumeric('order')->whereAlphaNumeric('booking')->middleware('throttle:booking-manage')->name('bookings.guest.move');
    Route::post('/orders/{order}/bookings/{booking}/complete', [GuestBookingsController::class, 'complete'])->whereAlphaNumeric('order')->whereAlphaNumeric('booking')->middleware('throttle:booking-manage')->name('bookings.guest.complete');
});
