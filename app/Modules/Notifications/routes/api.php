<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\Api\DeviceController;
use App\Modules\Notifications\Http\Controllers\Api\PreferenceController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix.
Route::middleware(['auth:sanctum', 'api.customer'])->group(function (): void {
    Route::get('/me/preferences', [PreferenceController::class, 'show'])->middleware('throttle:user-read')->name('me.preferences');
    Route::put('/me/preferences', [PreferenceController::class, 'update'])->middleware('throttle:write')->name('me.preferences.update');
    Route::post('/devices', [DeviceController::class, 'store'])->middleware('throttle:write')->name('devices.store');
    Route::delete('/devices/{id}', [DeviceController::class, 'destroy'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('devices.destroy');
});
