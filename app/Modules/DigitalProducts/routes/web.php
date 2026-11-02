<?php

declare(strict_types=1);

use App\Modules\DigitalProducts\Http\Controllers\Web\GuestLibraryController;
use App\Modules\DigitalProducts\Http\Controllers\Web\LibraryController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    // a signed-in customer: their own orders' downloads. The download is a POST (a link preview must not use up a download).
    Route::middleware(['auth:web', 'session.policy:web'])->group(function (): void {
        Route::get('/account/library', [LibraryController::class, 'index'])->name('digital.library');
        Route::post('/account/library/{entitlement}/keys', [LibraryController::class, 'keys'])->whereAlphaNumeric('entitlement')->middleware('throttle:digital-download')->name('digital.keys');
        Route::post('/account/library/{entitlement}/download', [LibraryController::class, 'download'])->whereAlphaNumeric('entitlement')->middleware('throttle:digital-download')->name('digital.download');
    });

    // a guest: the link we signed (expires), a code to the order's phone, then a short session for that one order.
    Route::get('/orders/{order}/downloads', [GuestLibraryController::class, 'show'])->whereAlphaNumeric('order')->middleware(['signed', 'throttle:public-read'])->name('digital.guest.library');
    Route::post('/orders/{order}/downloads/code', [GuestLibraryController::class, 'sendCode'])->whereAlphaNumeric('order')->middleware(['signed', 'throttle:otp'])->name('digital.guest.code');
    Route::post('/orders/{order}/downloads/verify', [GuestLibraryController::class, 'verify'])->whereAlphaNumeric('order')->middleware(['signed', 'throttle:otp'])->name('digital.guest.verify');
    Route::post('/orders/{order}/downloads/{entitlement}/keys', [GuestLibraryController::class, 'keys'])->whereAlphaNumeric('order')->whereAlphaNumeric('entitlement')->middleware('throttle:digital-download')->name('digital.guest.keys');
    Route::post('/orders/{order}/downloads/{entitlement}', [GuestLibraryController::class, 'download'])->whereAlphaNumeric('order')->whereAlphaNumeric('entitlement')->middleware('throttle:digital-download')->name('digital.guest.download');
});
