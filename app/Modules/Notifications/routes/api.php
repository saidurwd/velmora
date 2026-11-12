<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\Api\DeviceController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix.
Route::middleware(['auth:sanctum', 'api.customer'])->group(function (): void {
    Route::post('/devices', [DeviceController::class, 'store'])->middleware('throttle:write')->name('devices.store');
    Route::delete('/devices/{id}', [DeviceController::class, 'destroy'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('devices.destroy');
});
