<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\Admin\ExportController;
use App\Modules\Reporting\Http\Controllers\Admin\ReportController;
use App\Modules\Reporting\Http\Controllers\Admin\SalesSummaryController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix. Looking at reports = reports.view (and what a report itself needs); money-in/money-out columns also need
// reports.finance.view; asking for a file and downloading it need reports.export. The fixed paths come before the report code on purpose.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('reports')->name('reports.')->group(function (): void {
    Route::get('/', [ReportController::class, 'index'])->middleware(['permission:reports.view', 'throttle:reports'])->name('index');
    Route::get('/sales', [SalesSummaryController::class, 'index'])->middleware(['permission:reports.view', 'throttle:reports'])->name('sales');
    Route::get('/exports', [ExportController::class, 'index'])->middleware(['permission:reports.export', 'throttle:reports'])->name('exports');
    Route::get('/exports/{publicId}/download', [ExportController::class, 'download'])->whereAlphaNumeric('publicId')->middleware(['signed', 'permission:reports.export', 'throttle:reports'])->name('exports.download');
    Route::post('/{code}/export', [ExportController::class, 'store'])->where('code', '\w+')->middleware(['permission:reports.export', 'throttle:report-export'])->name('export');
    Route::get('/{code}', [ReportController::class, 'show'])->where('code', '\w+')->middleware(['permission:reports.view', 'throttle:reports'])->name('show');
});
