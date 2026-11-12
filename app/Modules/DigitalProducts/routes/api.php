<?php

declare(strict_types=1);

use App\Modules\DigitalProducts\Http\Controllers\Api\LibraryController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix. The signed-in customer's own purchases; every download is counted against the purchase's limit and recorded.
Route::middleware(['auth:sanctum', 'api.customer'])->prefix('library')->name('library.')->group(function (): void {
    Route::get('/', [LibraryController::class, 'index'])->middleware('throttle:user-read')->name('index');
    Route::post('/{id}/download', [LibraryController::class, 'download'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:digital-download')->name('download');
    Route::post('/{id}/keys', [LibraryController::class, 'keys'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:digital-download')->name('keys');
});
