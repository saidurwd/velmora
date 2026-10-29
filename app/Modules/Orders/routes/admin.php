<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\Admin\OrderController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('orders')->name('orders.')->group(function (): void {
    Route::get('/', [OrderController::class, 'index'])->middleware('permission:orders.view')->name('index');
    Route::get('/{order}', [OrderController::class, 'show'])->middleware('permission:orders.view')->name('show');
    Route::post('/{order}/transition', [OrderController::class, 'transition'])->middleware(['permission:orders.update', 'throttle:60,1'])->name('transition');
    Route::post('/{order}/notes', [OrderController::class, 'note'])->middleware(['permission:orders.update', 'throttle:60,1'])->name('note');
});
