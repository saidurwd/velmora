<?php

declare(strict_types=1);

use App\Modules\Engagement\Http\Controllers\Api\ReviewController;
use App\Modules\Engagement\Http\Controllers\Api\SupportController;
use App\Modules\Engagement\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix. Reading a product's reviews is public; everything else is the signed-in customer's own.
Route::get('/reviews', [ReviewController::class, 'forProduct'])->middleware(['throttle:public-read', 'etag:catalog'])->name('reviews');

Route::middleware(['auth:sanctum', 'api.customer'])->group(function (): void {
    Route::prefix('me')->name('me.')->group(function (): void {
        Route::get('/wishlist', [WishlistController::class, 'index'])->middleware('throttle:user-read')->name('wishlist');
        Route::post('/wishlist', [WishlistController::class, 'add'])->middleware('throttle:write')->name('wishlist.add');
        Route::delete('/wishlist/{variant}', [WishlistController::class, 'remove'])->where('variant', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('wishlist.remove');
        Route::post('/wishlist/{variant}/cart', [WishlistController::class, 'toCart'])->where('variant', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('wishlist.cart');
        Route::post('/stock-alerts', [WishlistController::class, 'alertOn'])->middleware('throttle:write')->name('alerts.add');
        Route::delete('/stock-alerts/{variant}', [WishlistController::class, 'alertOff'])->where('variant', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('alerts.remove');
        Route::get('/reviews', [ReviewController::class, 'mine'])->middleware('throttle:user-read')->name('reviews');
        Route::post('/reviews/{id}/withdraw', [ReviewController::class, 'withdraw'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('reviews.withdraw');
    });

    Route::get('/orders/{id}/reviews', [ReviewController::class, 'reviewable'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:user-read')->name('orders.reviews');
    Route::post('/orders/{id}/reviews', [ReviewController::class, 'store'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('orders.reviews.store');

    Route::prefix('support/tickets')->name('support.tickets.')->group(function (): void {
        Route::get('/', [SupportController::class, 'index'])->middleware('throttle:user-read')->name('index');
        Route::post('/', [SupportController::class, 'store'])->middleware('throttle:support-new')->name('store');
        Route::get('/{id}', [SupportController::class, 'show'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:user-read')->name('show');
        Route::post('/{id}/messages', [SupportController::class, 'reply'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:support-reply')->name('reply');
        Route::post('/{id}/close', [SupportController::class, 'close'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('close');
    });
});
