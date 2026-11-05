<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\Web\PreferenceController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::middleware(['auth:web', 'session.policy:web'])->group(function (): void {
        Route::get('/account/preferences', [PreferenceController::class, 'show'])->name('account.preferences');
        Route::put('/account/preferences', [PreferenceController::class, 'update'])->middleware('throttle:write')->name('account.preferences.update');
    });
});
