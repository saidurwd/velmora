<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\Api\PaymentController;
use App\Modules\Payments\Http\Controllers\Web\PaymentNotificationController;
use Illuminate\Support\Facades\Route;

// Machine to machine: no session, no CSRF. Mounted under /api/v1 → POST /api/v1/webhooks/payments/{provider}
Route::post('/webhooks/payments/{provider}', [PaymentNotificationController::class, 'receive'])->middleware('throttle:webhook')->name('webhooks.payments');

// Paying for an order from the app: the signed-in owner, or a guest holding that order's X-Order-Token. The app never reports success; the status is what the server recorded.
Route::middleware(['api.customer.optional'])->group(function (): void {
    Route::post('/orders/{id}/payments', [PaymentController::class, 'initiate'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware(['throttle:critical', 'idempotent:required'])->name('orders.payments');
    Route::get('/orders/{id}/payment', [PaymentController::class, 'status'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:user-read')->name('orders.payment');
    Route::post('/orders/{id}/payment/verify', [PaymentController::class, 'verify'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:critical')->name('orders.payment.verify');
});
