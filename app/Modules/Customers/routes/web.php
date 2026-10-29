<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\Web\AccountController;
use App\Modules\Customers\Http\Controllers\Web\AddressController;
use App\Modules\Customers\Http\Controllers\Web\ProfileController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::middleware(['auth:web', 'session.policy:web'])->group(function (): void {
        Route::get('/account', [AccountController::class, 'show'])->name('account');
        Route::get('/account/profile', [ProfileController::class, 'show'])->name('account.profile');
        Route::get('/account/addresses', [AddressController::class, 'index'])->name('account.addresses');
        Route::get('/account/addresses/create', [AddressController::class, 'create'])->name('account.addresses.create');
        Route::post('/account/addresses', [AddressController::class, 'store'])->middleware('throttle:write')->name('account.addresses.store');
        Route::get('/account/addresses/{address}/edit', [AddressController::class, 'edit'])->name('account.addresses.edit');
        Route::put('/account/addresses/{address}', [AddressController::class, 'update'])->middleware('throttle:write')->name('account.addresses.update');
        Route::delete('/account/addresses/{address}', [AddressController::class, 'destroy'])->middleware('throttle:write')->name('account.addresses.destroy');
        Route::post('/account/addresses/{address}/default', [AddressController::class, 'makeDefault'])->middleware('throttle:write')->name('account.addresses.default');
        Route::put('/account/profile', [ProfileController::class, 'update'])->middleware('throttle:write')->name('account.profile.update');
    });
});
