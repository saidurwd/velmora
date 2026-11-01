<?php

declare(strict_types=1);

use App\Modules\Shipping\Http\Controllers\Web\TrackingController;
use Illuminate\Support\Facades\Route;

// Reached only through a link we signed and that expires; the order's public id is the only id in it (never a sequence number).
Route::localized(function (): void {
    Route::get('/orders/{order}/track', [TrackingController::class, 'show'])->middleware(['signed', 'throttle:public-read'])->whereAlphaNumeric('order')->name('orders.track');
});
