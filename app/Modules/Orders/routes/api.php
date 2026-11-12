<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\Api\CheckoutController;
use App\Modules\Orders\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix. A guest (X-Cart-Token) or a signed-in customer; a wrong bearer token is refused, never read as a guest.
Route::prefix('checkout')->name('checkout.')->middleware(['api.customer.optional'])->group(function (): void {
    Route::post('/quote', [CheckoutController::class, 'quote'])->middleware('throttle:critical')->name('quote');
    Route::post('/phone-code', [CheckoutController::class, 'phoneCode'])->middleware('throttle:otp')->name('phone-code');
    Route::post('/phone-confirm', [CheckoutController::class, 'phoneConfirm'])->middleware('throttle:auth')->name('phone-confirm');
    Route::post('/orders', [CheckoutController::class, 'place'])->middleware(['throttle:critical', 'idempotent:required'])->name('orders');
});

// The customer's orders. A guest holding the order's own token (X-Order-Token) may look at and get the invoice of THAT order; every other action is the signed-in owner's.
Route::middleware(['api.customer.optional'])->group(function (): void {
    Route::get('/orders/{id}', [OrderController::class, 'show'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:user-read')->name('orders.show');
    Route::get('/orders/{id}/invoice', [OrderController::class, 'invoice'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:user-read')->name('orders.invoice');
});
Route::middleware(['auth:sanctum', 'api.customer'])->group(function (): void {
    Route::get('/orders', [OrderController::class, 'index'])->middleware('throttle:user-read')->name('orders.index');
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:critical')->name('orders.cancel');
    Route::post('/orders/{id}/reorder', [OrderController::class, 'reorder'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('orders.reorder');
});
