<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\Web\AccountController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::middleware(['auth:web', 'session.policy:web'])->group(function (): void {
        Route::get('/account', [AccountController::class, 'show'])->name('account');
    });
});
