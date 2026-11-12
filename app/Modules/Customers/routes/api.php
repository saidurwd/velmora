<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\Api\AddressController;
use App\Modules\Customers\Http\Controllers\Api\GeographyController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix. Reference data is public; the address book is the signed-in customer's own.
Route::middleware(['throttle:public-read', 'etag:config'])->prefix('geo')->name('geo.')->group(function (): void {
    Route::get('/divisions', [GeographyController::class, 'divisions'])->name('divisions');
    Route::get('/districts', [GeographyController::class, 'districts'])->name('districts');
    Route::get('/upazilas', [GeographyController::class, 'upazilas'])->name('upazilas');
});

Route::middleware(['auth:sanctum', 'api.customer'])->prefix('me/addresses')->name('me.addresses.')->group(function (): void {
    Route::get('/', [AddressController::class, 'index'])->middleware('throttle:user-read')->name('index');
    Route::post('/', [AddressController::class, 'store'])->middleware('throttle:write')->name('store');
    Route::put('/{id}', [AddressController::class, 'update'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('update');
    Route::delete('/{id}', [AddressController::class, 'destroy'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('destroy');
    Route::post('/{id}/default', [AddressController::class, 'makeDefault'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('default');
});
