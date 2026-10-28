<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\Web\AccountController;
use App\Modules\Customers\Http\Controllers\Web\ProfileController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::middleware(['auth:web', 'session.policy:web'])->group(function (): void {
        Route::get('/account', [AccountController::class, 'show'])->name('account');
        Route::get('/account/profile', [ProfileController::class, 'show'])->name('account.profile');
        Route::put('/account/profile', [ProfileController::class, 'update'])->middleware('throttle:write')->name('account.profile.update');
    });
});
