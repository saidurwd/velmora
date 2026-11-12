<?php

declare(strict_types=1);

use App\Modules\Cart\Http\Controllers\Api\CartController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix. A guest (X-Cart-Token) or a signed-in customer; a wrong bearer token is refused, never read as a guest.
Route::prefix('cart')->name('cart.')->group(function (): void {
    Route::middleware(['api.customer.optional'])->group(function (): void {
        Route::get('/', [CartController::class, 'show'])->middleware('throttle:user-read')->name('show');
        Route::post('/items', [CartController::class, 'add'])->middleware('throttle:write')->name('items.add');
        Route::patch('/items/{variant}', [CartController::class, 'setQuantity'])->where('variant', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('items.set');
        Route::delete('/items/{variant}', [CartController::class, 'remove'])->where('variant', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('items.remove');
        Route::post('/coupons', [CartController::class, 'applyCoupon'])->middleware('throttle:write')->name('coupons.apply');
        Route::delete('/coupons/{code}', [CartController::class, 'removeCoupon'])->middleware('throttle:write')->name('coupons.remove');
        Route::post('/acknowledge', [CartController::class, 'acknowledge'])->middleware('throttle:write')->name('acknowledge');
    });

    Route::post('/merge', [CartController::class, 'merge'])->middleware(['auth:sanctum', 'api.customer', 'throttle:write'])->name('merge');
});
