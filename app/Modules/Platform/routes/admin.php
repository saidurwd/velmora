<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:admin', 'session.policy:admin'])->group(function (): void {
    Route::get('/', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');
});
