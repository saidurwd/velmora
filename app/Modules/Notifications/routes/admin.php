<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\Admin\LogController;
use App\Modules\Notifications\Http\Controllers\Admin\TemplateController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix. Looking = notifications.view; changing the words or sending a message again = notifications.manage.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('notifications')->name('notifications.')->group(function (): void {
    Route::get('/templates', [TemplateController::class, 'index'])->middleware('permission:notifications.view')->name('templates');
    Route::get('/templates/{code}', [TemplateController::class, 'edit'])->where('code', '[a-z0-9._-]+')->middleware('permission:notifications.view')->name('templates.edit');
    Route::put('/templates/{code}', [TemplateController::class, 'save'])->where('code', '[a-z0-9._-]+')->middleware(['permission:notifications.manage', 'throttle:notifications-actions'])->name('templates.save');
    Route::put('/templates/{code}/preview', [TemplateController::class, 'preview'])->where('code', '[a-z0-9._-]+')->middleware(['permission:notifications.manage', 'throttle:notifications-actions'])->name('templates.preview');

    Route::get('/logs', [LogController::class, 'index'])->middleware('permission:notifications.view')->name('logs');
    Route::post('/logs/{log}/retry', [LogController::class, 'retry'])->whereAlphaNumeric('log')->middleware(['permission:notifications.manage', 'throttle:notifications-actions'])->name('logs.retry');
});
