<?php

declare(strict_types=1);

use App\Modules\Engagement\Http\Controllers\Admin\CannedReplyController;
use App\Modules\Engagement\Http\Controllers\Admin\ContactSettingsController;
use App\Modules\Engagement\Http\Controllers\Admin\ReviewQueueController;
use App\Modules\Engagement\Http\Controllers\Admin\SupportDeskController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix. Everything here needs reviews.moderate.
Route::middleware(['auth:admin', 'session.policy:admin', 'permission:reviews.moderate'])->prefix('reviews')->name('reviews.')->group(function (): void {
    Route::get('/', [ReviewQueueController::class, 'index'])->name('index');
    Route::post('/{review}/approve', [ReviewQueueController::class, 'approve'])->whereAlphaNumeric('review')->middleware('throttle:reviews-staff')->name('approve');
    Route::post('/{review}/reject', [ReviewQueueController::class, 'reject'])->whereAlphaNumeric('review')->middleware('throttle:reviews-staff')->name('reject');
});

// The support desk. Looking = support.view; answering and notes = support.reply; closing, giving tickets to others, canned replies and contact details = support.manage.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('support')->name('support.')->group(function (): void {
    Route::get('/', [SupportDeskController::class, 'index'])->middleware('permission:support.view')->name('index');
    // before /{ticket}, so these words are never taken for a ticket
    Route::prefix('canned')->name('canned.')->middleware('permission:support.manage')->group(function (): void {
        Route::get('/', [CannedReplyController::class, 'index'])->name('index');
        Route::get('/create', [CannedReplyController::class, 'create'])->name('create');
        Route::post('/', [CannedReplyController::class, 'save'])->middleware('throttle:support-staff')->name('store');
        Route::get('/{reply}/edit', [CannedReplyController::class, 'edit'])->whereAlphaNumeric('reply')->name('edit');
        Route::put('/{reply}', [CannedReplyController::class, 'save'])->whereAlphaNumeric('reply')->middleware('throttle:support-staff')->name('update');
        Route::delete('/{reply}', [CannedReplyController::class, 'destroy'])->whereAlphaNumeric('reply')->middleware('throttle:support-staff')->name('destroy');
    });
    Route::get('/contact', [ContactSettingsController::class, 'edit'])->middleware('permission:support.manage')->name('contact');
    Route::put('/contact', [ContactSettingsController::class, 'update'])->middleware(['permission:support.manage', 'throttle:support-staff'])->name('contact.update');
    Route::get('/files/{file}', [SupportDeskController::class, 'file'])->whereAlphaNumeric('file')->middleware(['permission:support.view', 'throttle:support-staff'])->name('file');

    Route::get('/{ticket}', [SupportDeskController::class, 'show'])->whereAlphaNumeric('ticket')->middleware('permission:support.view')->name('show');
    Route::post('/{ticket}/reply', [SupportDeskController::class, 'reply'])->whereAlphaNumeric('ticket')->middleware(['permission:support.reply', 'throttle:support-staff'])->name('reply');
    Route::post('/{ticket}/assign', [SupportDeskController::class, 'assign'])->whereAlphaNumeric('ticket')->middleware(['permission:support.reply', 'throttle:support-staff'])->name('assign');
    Route::post('/{ticket}/close', [SupportDeskController::class, 'close'])->whereAlphaNumeric('ticket')->middleware(['permission:support.manage', 'throttle:support-staff'])->name('close');
    Route::post('/{ticket}/reopen', [SupportDeskController::class, 'reopen'])->whereAlphaNumeric('ticket')->middleware(['permission:support.manage', 'throttle:support-staff'])->name('reopen');
});
