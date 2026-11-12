<?php

declare(strict_types=1);

use App\Modules\Bookings\Http\Controllers\Api\BookingController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix. Looking at free times is public; holding one is for a guest (X-Cart-Token) or a customer; managing bookings is the signed-in customer's own.
Route::get('/services/{sku}/slots', [BookingController::class, 'slots'])->where('sku', '[A-Za-z0-9._-]+')->middleware('throttle:search')->name('services.slots');
Route::post('/services/{sku}/hold', [BookingController::class, 'hold'])->where('sku', '[A-Za-z0-9._-]+')->middleware(['api.customer.optional', 'throttle:critical'])->name('services.hold');

Route::middleware(['auth:sanctum', 'api.customer'])->prefix('bookings')->name('bookings.')->group(function (): void {
    Route::get('/', [BookingController::class, 'index'])->middleware('throttle:user-read')->name('index');
    Route::get('/{id}', [BookingController::class, 'show'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:user-read')->name('show');
    Route::post('/{id}/cancel', [BookingController::class, 'cancel'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('cancel');
    Route::post('/{id}/reschedule', [BookingController::class, 'reschedule'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('reschedule');
    Route::post('/{id}/complete', [BookingController::class, 'complete'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('complete');
});
