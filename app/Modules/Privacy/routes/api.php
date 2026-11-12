<?php

declare(strict_types=1);

use App\Modules\Privacy\Http\Controllers\Api\PrivacyController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix. The signed-in customer's own requests; asking, downloading and cancelling need a fresh confirmation (POST /auth/reauth).
Route::middleware(['auth:sanctum', 'api.customer'])->prefix('me/privacy')->name('me.privacy.')->group(function (): void {
    Route::get('/', [PrivacyController::class, 'index'])->middleware('throttle:user-read')->name('index');
    Route::middleware('api.recent_auth')->group(function (): void {
        Route::post('/export', [PrivacyController::class, 'requestExport'])->middleware('throttle:privacy')->name('export');
        Route::get('/exports/{id}', [PrivacyController::class, 'download'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:privacy')->name('exports.download');
        Route::post('/erasure', [PrivacyController::class, 'requestErasure'])->middleware('throttle:privacy')->name('erasure');
        Route::post('/erasure/{id}/cancel', [PrivacyController::class, 'cancelErasure'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:privacy')->name('erasure.cancel');
    });
});
