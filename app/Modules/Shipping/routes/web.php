<?php

declare(strict_types=1);

use App\Modules\Shipping\Http\Controllers\Web\ReturnPortalController;
use App\Modules\Shipping\Http\Controllers\Web\TrackingController;
use Illuminate\Support\Facades\Route;

// Reached only through a link we signed and that expires; the order's public id is the only id in it (never a sequence number).
Route::localized(function (): void {
    Route::get('/orders/{order}/track', [TrackingController::class, 'show'])->middleware(['signed', 'throttle:public-read'])->whereAlphaNumeric('order')->name('orders.track');

    // A signed-in customer's returns: ask from an order, follow, withdraw.
    Route::middleware(['auth:web', 'session.policy:web'])->group(function (): void {
        Route::get('/account/orders/{order}/return', [ReturnPortalController::class, 'create'])->whereAlphaNumeric('order')->name('account.returns.create');
        Route::post('/account/orders/{order}/return', [ReturnPortalController::class, 'store'])->whereAlphaNumeric('order')->middleware('throttle:write')->name('account.returns.store');
        Route::get('/account/returns', [ReturnPortalController::class, 'index'])->name('account.returns');
        Route::post('/account/returns/{return}/withdraw', [ReturnPortalController::class, 'withdraw'])->whereAlphaNumeric('return')->middleware('throttle:write')->name('account.returns.withdraw');
    });
});
