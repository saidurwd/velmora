<?php

declare(strict_types=1);

use App\Modules\Privacy\Http\Controllers\Web\PrivacyController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::middleware(['auth:web', 'session.policy:web'])->group(function (): void {
        Route::get('/account/privacy', [PrivacyController::class, 'show'])->name('account.privacy');
        // Asking for a copy, reading it, and asking to be erased need a sign-in confirmation from the last 10 minutes.
        Route::middleware('recent.auth')->group(function (): void {
            Route::post('/account/privacy/export', [PrivacyController::class, 'requestExport'])->middleware('throttle:privacy')->name('account.privacy.export');
            Route::get('/account/privacy/exports/{publicId}/download', [PrivacyController::class, 'download'])->whereAlphaNumeric('publicId')->middleware('throttle:privacy')->name('account.privacy.download');
            Route::post('/account/privacy/erasure', [PrivacyController::class, 'requestErasure'])->middleware('throttle:privacy')->name('account.privacy.erasure');
            Route::post('/account/privacy/erasure/{publicId}/cancel', [PrivacyController::class, 'cancelErasure'])->whereAlphaNumeric('publicId')->middleware('throttle:privacy')->name('account.privacy.erasure.cancel');
        });
    });
});
