<?php

declare(strict_types=1);

use App\Modules\Shipping\Http\Controllers\Api\CourierWebhookController;
use App\Modules\Shipping\Http\Controllers\Api\ReturnController;
use App\Modules\Shipping\Http\Controllers\Api\TrackingController;
use Illuminate\Support\Facades\Route;

// Machine to machine: no session, no CSRF. Mounted under /api/v1 → POST /api/v1/webhooks/couriers/{courier}. The adapter authenticates the request.
Route::post('/webhooks/couriers/{courier}', CourierWebhookController::class)->middleware('throttle:courier-webhooks')->whereAlphaNumeric('courier')->name('webhooks.couriers');

// Tracking: the signed-in owner, or a guest holding that order's X-Order-Token. Returns: the signed-in owner only.
Route::get('/orders/{id}/tracking', [TrackingController::class, 'show'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware(['api.customer.optional', 'throttle:user-read'])->name('orders.tracking');
Route::middleware(['auth:sanctum', 'api.customer'])->group(function (): void {
    Route::get('/orders/{id}/returns/eligibility', [ReturnController::class, 'eligibility'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:user-read')->name('orders.returns.eligibility');
    Route::post('/orders/{id}/returns', [ReturnController::class, 'store'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('orders.returns');
    Route::get('/returns', [ReturnController::class, 'index'])->middleware('throttle:user-read')->name('returns.index');
    Route::post('/returns/{id}/withdraw', [ReturnController::class, 'withdraw'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('returns.withdraw');
});
