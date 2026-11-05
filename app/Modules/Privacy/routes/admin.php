<?php

declare(strict_types=1);

use App\Modules\Privacy\Http\Controllers\Admin\RequestController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix. Looking = privacy.view; approving or rejecting an erasure = privacy.manage.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('privacy')->name('privacy.')->group(function (): void {
    Route::get('/', [RequestController::class, 'index'])->middleware('permission:privacy.view')->name('index');
    Route::get('/{publicId}', [RequestController::class, 'show'])->whereAlphaNumeric('publicId')->middleware('permission:privacy.view')->name('show');
    Route::post('/{publicId}/approve', [RequestController::class, 'approve'])->whereAlphaNumeric('publicId')->middleware(['permission:privacy.manage', 'throttle:privacy-staff'])->name('approve');
    Route::post('/{publicId}/reject', [RequestController::class, 'reject'])->whereAlphaNumeric('publicId')->middleware(['permission:privacy.manage', 'throttle:privacy-staff'])->name('reject');
});
