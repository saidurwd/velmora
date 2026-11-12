<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\Api\GeographyController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix. Reference data, public.
Route::middleware(['throttle:public-read', 'etag:config'])->prefix('geo')->name('geo.')->group(function (): void {
    Route::get('/divisions', [GeographyController::class, 'divisions'])->name('divisions');
    Route::get('/districts', [GeographyController::class, 'districts'])->name('districts');
    Route::get('/upazilas', [GeographyController::class, 'upazilas'])->name('upazilas');
});
