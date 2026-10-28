<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Admin\CategoryController;
use App\Modules\Catalog\Http\Controllers\Admin\TaxonomyController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('catalog')->name('catalog.')->group(function (): void {
    Route::prefix('categories')->name('categories.')->group(function (): void {
        Route::get('/', [CategoryController::class, 'index'])->middleware('permission:catalog.view')->name('index');
        Route::get('/create', [CategoryController::class, 'create'])->middleware('permission:catalog.create')->name('create');
        Route::post('/', [CategoryController::class, 'store'])->middleware('permission:catalog.create')->name('store');
        Route::get('/{category}/edit', [CategoryController::class, 'edit'])->middleware('permission:catalog.update')->name('edit');
        Route::put('/{category}', [CategoryController::class, 'update'])->middleware('permission:catalog.update')->name('update');
        Route::delete('/{category}', [CategoryController::class, 'destroy'])->middleware('permission:catalog.delete')->name('destroy');
    });

    Route::prefix('{type}')->whereIn('type', ['brands', 'tags', 'collections'])->name('taxonomy.')->group(function (): void {
        Route::get('/', [TaxonomyController::class, 'index'])->middleware('permission:catalog.view')->name('index');
        Route::get('/create', [TaxonomyController::class, 'create'])->middleware('permission:catalog.create')->name('create');
        Route::post('/', [TaxonomyController::class, 'store'])->middleware('permission:catalog.create')->name('store');
        Route::get('/{item}/edit', [TaxonomyController::class, 'edit'])->middleware('permission:catalog.update')->name('edit');
        Route::put('/{item}', [TaxonomyController::class, 'update'])->middleware('permission:catalog.update')->name('update');
        Route::delete('/{item}', [TaxonomyController::class, 'destroy'])->middleware('permission:catalog.delete')->name('destroy');
    });
});
