<?php

declare(strict_types=1);

use App\Modules\Bookings\Http\Controllers\Web\BookController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    // choosing a time for a service: anyone may look at the free times; pressing one holds it and puts the booking in their own cart
    Route::get('/book/{sku}', [BookController::class, 'show'])->where('sku', '[A-Za-z0-9._-]+')->middleware('throttle:public-read')->name('bookings.book');
    Route::post('/book/{sku}/hold', [BookController::class, 'hold'])->where('sku', '[A-Za-z0-9._-]+')->middleware('throttle:booking-hold')->name('bookings.hold');
});
