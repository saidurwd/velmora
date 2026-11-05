<?php

declare(strict_types=1);

use App\Modules\Engagement\Http\Controllers\Web\StockAlertController;
use App\Modules\Engagement\Http\Controllers\Web\WishlistController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::middleware(['auth:web', 'session.policy:web'])->group(function (): void {
        Route::get('/account/wishlist', [WishlistController::class, 'index'])->name('account.wishlist');
        Route::post('/account/wishlist', [WishlistController::class, 'add'])->middleware('throttle:write')->name('account.wishlist.add');
        Route::delete('/account/wishlist/{variant}', [WishlistController::class, 'remove'])->whereAlphaNumeric('variant')->middleware('throttle:write')->name('account.wishlist.remove');
        Route::post('/account/wishlist/{variant}/cart', [WishlistController::class, 'toCart'])->whereAlphaNumeric('variant')->middleware('throttle:write')->name('account.wishlist.cart');

        Route::post('/account/stock-alerts', [StockAlertController::class, 'add'])->middleware('throttle:write')->name('account.alerts.add');
        Route::delete('/account/stock-alerts/{variant}', [StockAlertController::class, 'remove'])->whereAlphaNumeric('variant')->middleware('throttle:write')->name('account.alerts.remove');
    });
});
