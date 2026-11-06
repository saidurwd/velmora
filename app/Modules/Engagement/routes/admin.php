<?php

declare(strict_types=1);

use App\Modules\Engagement\Http\Controllers\Admin\ReviewQueueController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix. Everything here needs reviews.moderate.
Route::middleware(['auth:admin', 'session.policy:admin', 'permission:reviews.moderate'])->prefix('reviews')->name('reviews.')->group(function (): void {
    Route::get('/', [ReviewQueueController::class, 'index'])->name('index');
    Route::post('/{review}/approve', [ReviewQueueController::class, 'approve'])->whereAlphaNumeric('review')->middleware('throttle:reviews-staff')->name('approve');
    Route::post('/{review}/reject', [ReviewQueueController::class, 'reject'])->whereAlphaNumeric('review')->middleware('throttle:reviews-staff')->name('reject');
});
