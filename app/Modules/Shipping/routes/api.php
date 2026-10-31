<?php

declare(strict_types=1);

use App\Modules\Shipping\Http\Controllers\Api\CourierWebhookController;
use Illuminate\Support\Facades\Route;

// Machine to machine: no session, no CSRF. Mounted under /api/v1 → POST /api/v1/webhooks/couriers/{courier}. The adapter authenticates the request.
Route::post('/webhooks/couriers/{courier}', CourierWebhookController::class)->middleware('throttle:courier-webhooks')->whereAlphaNumeric('courier')->name('webhooks.couriers');
