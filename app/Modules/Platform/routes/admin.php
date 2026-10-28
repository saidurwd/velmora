<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\Admin\BrandingController;
use App\Modules\Platform\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:admin', 'session.policy:admin'])->group(function (): void {
    Route::get('/settings/branding', [BrandingController::class, 'edit'])->middleware('permission:settings.view')->name('settings.branding');
    Route::put('/settings/branding', [BrandingController::class, 'update'])->middleware('permission:settings.manage')->name('settings.branding.update');
    Route::get('/', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');
});
