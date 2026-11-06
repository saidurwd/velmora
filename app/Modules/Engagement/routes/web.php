<?php

declare(strict_types=1);

use App\Modules\Engagement\Http\Controllers\Web\ContactController;
use App\Modules\Engagement\Http\Controllers\Web\ReviewController;
use App\Modules\Engagement\Http\Controllers\Web\StockAlertController;
use App\Modules\Engagement\Http\Controllers\Web\SupportController;
use App\Modules\Engagement\Http\Controllers\Web\WishlistController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::get('/contact', [ContactController::class, 'show'])->middleware('throttle:public-read')->name('contact');

    Route::middleware(['auth:web', 'session.policy:web'])->group(function (): void {
        Route::get('/account/wishlist', [WishlistController::class, 'index'])->name('account.wishlist');
        Route::post('/account/wishlist', [WishlistController::class, 'add'])->middleware('throttle:write')->name('account.wishlist.add');
        Route::delete('/account/wishlist/{variant}', [WishlistController::class, 'remove'])->whereAlphaNumeric('variant')->middleware('throttle:write')->name('account.wishlist.remove');
        Route::post('/account/wishlist/{variant}/cart', [WishlistController::class, 'toCart'])->whereAlphaNumeric('variant')->middleware('throttle:write')->name('account.wishlist.cart');

        Route::get('/account/orders/{order}/reviews/{variant}', [ReviewController::class, 'create'])->whereAlphaNumeric('order')->whereAlphaNumeric('variant')->name('account.reviews.create');
        Route::post('/account/orders/{order}/reviews/{variant}', [ReviewController::class, 'store'])->whereAlphaNumeric('order')->whereAlphaNumeric('variant')->middleware('throttle:reviews')->name('account.reviews.store');
        Route::get('/account/reviews', [ReviewController::class, 'mine'])->name('account.reviews');
        Route::post('/account/reviews/{review}/withdraw', [ReviewController::class, 'withdraw'])->whereAlphaNumeric('review')->middleware('throttle:write')->name('account.reviews.withdraw');

        // Support: customers only. "new" is registered before the {ticket} pages so it is never taken for a ticket.
        Route::get('/account/support', [SupportController::class, 'index'])->name('account.support');
        Route::get('/account/support/new', [SupportController::class, 'create'])->name('account.support.create');
        Route::post('/account/support', [SupportController::class, 'store'])->middleware('throttle:support-new')->name('account.support.store');
        Route::get('/account/support/files/{file}', [SupportController::class, 'file'])->whereAlphaNumeric('file')->middleware('throttle:support-read')->name('account.support.file');
        Route::get('/account/support/{ticket}', [SupportController::class, 'show'])->whereAlphaNumeric('ticket')->name('account.support.show');
        Route::post('/account/support/{ticket}/reply', [SupportController::class, 'reply'])->whereAlphaNumeric('ticket')->middleware('throttle:support-reply')->name('account.support.reply');
        Route::post('/account/support/{ticket}/close', [SupportController::class, 'close'])->whereAlphaNumeric('ticket')->middleware('throttle:write')->name('account.support.close');

        Route::post('/account/stock-alerts', [StockAlertController::class, 'add'])->middleware('throttle:write')->name('account.alerts.add');
        Route::delete('/account/stock-alerts/{variant}', [StockAlertController::class, 'remove'])->whereAlphaNumeric('variant')->middleware('throttle:write')->name('account.alerts.remove');
    });
});
