<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\Web\AccountOrderController;
use App\Modules\Orders\Http\Controllers\Web\CheckoutController;
use Illuminate\Support\Facades\Route;

// Guest checkout is allowed; the cart comes from the shopper's own cookie or account.
Route::localized(function (): void {
    Route::get('/checkout', [CheckoutController::class, 'details'])->name('checkout.details');
    Route::get('/checkout/review', [CheckoutController::class, 'review'])->name('checkout.review');
    Route::middleware('throttle:write')->group(function (): void {
        Route::post('/checkout', [CheckoutController::class, 'saveDetails'])->name('checkout.save');
        Route::post('/checkout/review', [CheckoutController::class, 'choose'])->name('checkout.choose');
        Route::post('/checkout/phone-confirm', [CheckoutController::class, 'verifyPhoneCode'])->name('checkout.phone.verify');
    });
    Route::post('/checkout/phone-code', [CheckoutController::class, 'sendPhoneCode'])->middleware('throttle:otp')->name('checkout.phone.send');
    Route::post('/checkout/place', [CheckoutController::class, 'place'])->middleware('throttle:critical')->name('checkout.place');

    // The link in the confirmation email / page: signed, so only someone holding it can read the order; it expires.
    Route::get('/orders/{order}/thanks', [CheckoutController::class, 'confirmation'])->middleware(['signed', 'throttle:public-read'])->name('checkout.thanks');

    // On that page the shopper can make an account (or, signed in, use theirs) and the order goes into it. The signed link on the form is the proof of ownership.
    Route::post('/orders/{order}/account', [AccountOrderController::class, 'createAccount'])->middleware(['signed', 'throttle:auth'])->name('checkout.account.create');
    Route::post('/orders/{order}/attach', [AccountOrderController::class, 'attach'])->middleware(['signed', 'auth:web', 'session.policy:web', 'throttle:write'])->name('checkout.account.attach');

    Route::middleware(['auth:web', 'session.policy:web'])->group(function (): void {
        Route::get('/account/orders', [AccountOrderController::class, 'index'])->name('account.orders');
        Route::get('/account/orders/{order}', [AccountOrderController::class, 'show'])->whereAlphaNumeric('order')->name('account.orders.show');
        Route::post('/account/orders/{order}/cancel', [AccountOrderController::class, 'cancel'])->whereAlphaNumeric('order')->middleware('throttle:write')->name('account.orders.cancel');
    });
});
