<?php

declare(strict_types=1);

use App\Modules\Cart\Http\Controllers\Web\CartController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::get('/cart', [CartController::class, 'show'])->name('cart.show');
    Route::middleware('throttle:write')->group(function (): void {
        Route::post('/cart/items', [CartController::class, 'add'])->name('cart.add');
        Route::patch('/cart/items/{variant}', [CartController::class, 'update'])->name('cart.update');
        Route::delete('/cart/items/{variant}', [CartController::class, 'destroy'])->name('cart.remove');
        Route::post('/cart/acknowledge', [CartController::class, 'acknowledge'])->name('cart.acknowledge');
    });
});
