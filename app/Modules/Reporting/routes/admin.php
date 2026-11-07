<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\Admin\SalesSummaryController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix. Looking at reports = reports.view; the money-in/money-out rows inside also need reports.finance.view.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('reports')->name('reports.')->group(function (): void {
    Route::get('/sales', [SalesSummaryController::class, 'index'])->middleware(['permission:reports.view', 'throttle:reports'])->name('sales');
});
