<?php

declare(strict_types=1);

use App\Modules\Cms\Http\Controllers\Admin\BannerController;
use App\Modules\Cms\Http\Controllers\Admin\PageController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('cms')->name('cms.')->group(function (): void {
    Route::prefix('pages')->name('pages.')->group(function (): void {
        Route::get('/', [PageController::class, 'index'])->middleware('permission:cms.view')->name('index');
        Route::get('/create', [PageController::class, 'create'])->middleware('permission:cms.edit')->name('create');
        Route::post('/', [PageController::class, 'store'])->middleware('permission:cms.edit')->name('store');
        Route::get('/{page}/edit', [PageController::class, 'edit'])->middleware('permission:cms.view')->name('edit');
        Route::put('/{page}', [PageController::class, 'update'])->middleware('permission:cms.edit')->name('update');
        Route::post('/{page}/transition', [PageController::class, 'transition'])->middleware('permission:cms.publish')->name('transition');
        Route::delete('/{page}', [PageController::class, 'destroy'])->middleware('permission:cms.edit')->name('destroy');
    });

    Route::prefix('banners')->name('banners.')->group(function (): void {
        Route::get('/', [BannerController::class, 'index'])->middleware('permission:cms.view')->name('index');
        Route::get('/create', [BannerController::class, 'create'])->middleware('permission:cms.edit')->name('create');
        Route::post('/', [BannerController::class, 'store'])->middleware('permission:cms.edit')->name('store');
        Route::get('/{banner}/edit', [BannerController::class, 'edit'])->middleware('permission:cms.view')->name('edit');
        Route::put('/{banner}', [BannerController::class, 'update'])->middleware('permission:cms.edit')->name('update');
        Route::delete('/{banner}', [BannerController::class, 'destroy'])->middleware('permission:cms.edit')->name('destroy');
    });
});
