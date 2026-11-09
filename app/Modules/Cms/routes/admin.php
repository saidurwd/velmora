<?php

declare(strict_types=1);

use App\Modules\Cms\Http\Controllers\Admin\BannerController;
use App\Modules\Cms\Http\Controllers\Admin\FaqController;
use App\Modules\Cms\Http\Controllers\Admin\HomeSectionController;
use App\Modules\Cms\Http\Controllers\Admin\MenuController;
use App\Modules\Cms\Http\Controllers\Admin\PageController;
use App\Modules\Cms\Http\Controllers\Admin\PolicyController;
use App\Modules\Cms\Http\Controllers\Admin\TranslationGapController;
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
        Route::get('/{page}/versions/{number}/preview', [PageController::class, 'preview'])->whereNumber('number')->middleware('permission:cms.view')->name('preview');
        Route::delete('/{page}', [PageController::class, 'destroy'])->middleware('permission:cms.edit')->name('destroy');
    });

    Route::prefix('policies')->name('policies.')->group(function (): void {
        Route::get('/', [PolicyController::class, 'index'])->middleware('permission:cms.view')->name('index');
        Route::post('/{kind}/assign', [PolicyController::class, 'assign'])->where('kind', 'terms|privacy|returns|shipping|cod')->middleware(['permission:cms.policies.manage', 'throttle:60,1'])->name('assign');
    });

    Route::prefix('menus')->name('menus.')->group(function (): void {
        Route::get('/', [MenuController::class, 'index'])->middleware('permission:cms.view')->name('index');
        Route::get('/create', [MenuController::class, 'create'])->middleware('permission:cms.edit')->name('create');
        Route::post('/', [MenuController::class, 'store'])->middleware(['permission:cms.edit', 'throttle:60,1'])->name('store');
        Route::get('/{item}/edit', [MenuController::class, 'edit'])->whereAlphaNumeric('item')->middleware('permission:cms.view')->name('edit');
        Route::put('/{item}', [MenuController::class, 'update'])->whereAlphaNumeric('item')->middleware(['permission:cms.edit', 'throttle:60,1'])->name('update');
        Route::delete('/{item}', [MenuController::class, 'destroy'])->whereAlphaNumeric('item')->middleware(['permission:cms.edit', 'throttle:60,1'])->name('destroy');
        Route::post('/{item}/move', [MenuController::class, 'move'])->whereAlphaNumeric('item')->middleware(['permission:cms.edit', 'throttle:60,1'])->name('move');
    });

    Route::prefix('home')->name('home.')->group(function (): void {
        Route::get('/', [HomeSectionController::class, 'index'])->middleware('permission:cms.view')->name('index');
        Route::get('/create', [HomeSectionController::class, 'create'])->middleware('permission:cms.edit')->name('create');
        Route::post('/', [HomeSectionController::class, 'store'])->middleware(['permission:cms.edit', 'throttle:60,1'])->name('store');
        Route::get('/{section}/edit', [HomeSectionController::class, 'edit'])->whereAlphaNumeric('section')->middleware('permission:cms.view')->name('edit');
        Route::put('/{section}', [HomeSectionController::class, 'update'])->whereAlphaNumeric('section')->middleware(['permission:cms.edit', 'throttle:60,1'])->name('update');
        Route::delete('/{section}', [HomeSectionController::class, 'destroy'])->whereAlphaNumeric('section')->middleware(['permission:cms.edit', 'throttle:60,1'])->name('destroy');
        Route::post('/{section}/move', [HomeSectionController::class, 'move'])->whereAlphaNumeric('section')->middleware(['permission:cms.edit', 'throttle:60,1'])->name('move');
    });

    Route::get('translations', [TranslationGapController::class, 'index'])->middleware('permission:cms.view')->name('translations');

    Route::prefix('faq')->name('faq.')->group(function (): void {
        Route::get('/', [FaqController::class, 'index'])->middleware('permission:cms.view')->name('index');
        Route::get('/create', [FaqController::class, 'create'])->middleware('permission:cms.edit')->name('create');
        Route::post('/', [FaqController::class, 'save'])->middleware(['permission:cms.edit', 'throttle:60,1'])->name('store');
        Route::get('/{entry}/edit', [FaqController::class, 'edit'])->whereAlphaNumeric('entry')->middleware('permission:cms.view')->name('edit');
        Route::put('/{entry}', [FaqController::class, 'save'])->whereAlphaNumeric('entry')->middleware(['permission:cms.edit', 'throttle:60,1'])->name('update');
        Route::delete('/{entry}', [FaqController::class, 'destroy'])->whereAlphaNumeric('entry')->middleware(['permission:cms.edit', 'throttle:60,1'])->name('destroy');
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
