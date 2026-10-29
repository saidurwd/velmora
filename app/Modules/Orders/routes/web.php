<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\Web\CheckoutController;
use Illuminate\Support\Facades\Route;

// Guest checkout is allowed; the cart comes from the shopper's own cookie or account.
Route::localized(function (): void {
    Route::get('/checkout', [CheckoutController::class, 'details'])->name('checkout.details');
    Route::get('/checkout/review', [CheckoutController::class, 'review'])->name('checkout.review');
    Route::middleware('throttle:write')->group(function (): void {
        Route::post('/checkout', [CheckoutController::class, 'saveDetails'])->name('checkout.save');
        Route::post('/checkout/review', [CheckoutController::class, 'choose'])->name('checkout.choose');
    });
    Route::post('/checkout/place', [CheckoutController::class, 'place'])->middleware('throttle:critical')->name('checkout.place');

    // The link in the confirmation email / page: signed, so only someone holding it can read the order; it expires.
    Route::get('/orders/{order}/thanks', [CheckoutController::class, 'confirmation'])->middleware(['signed', 'throttle:public-read'])->name('checkout.thanks');
});
