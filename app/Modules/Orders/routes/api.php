<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\Api\CheckoutController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix. A guest (X-Cart-Token) or a signed-in customer; a wrong bearer token is refused, never read as a guest.
Route::prefix('checkout')->name('checkout.')->middleware(['api.customer.optional'])->group(function (): void {
    Route::post('/quote', [CheckoutController::class, 'quote'])->middleware('throttle:critical')->name('quote');
    Route::post('/phone-code', [CheckoutController::class, 'phoneCode'])->middleware('throttle:otp')->name('phone-code');
    Route::post('/phone-confirm', [CheckoutController::class, 'phoneConfirm'])->middleware('throttle:auth')->name('phone-confirm');
    Route::post('/orders', [CheckoutController::class, 'place'])->middleware(['throttle:critical', 'idempotent:required'])->name('orders');
});
