<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\Web\PaymentNotificationController;
use Illuminate\Support\Facades\Route;

// Machine to machine: no session, no CSRF. Mounted under /api/v1 → POST /api/v1/webhooks/payments/{provider}
Route::post('/webhooks/payments/{provider}', [PaymentNotificationController::class, 'receive'])->middleware('throttle:webhook')->name('webhooks.payments');
