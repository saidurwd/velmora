<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Admin\AttributeController;
use App\Modules\Catalog\Http\Controllers\Admin\CategoryController;
use App\Modules\Catalog\Http\Controllers\Admin\ImportController;
use App\Modules\Catalog\Http\Controllers\Admin\MediaController;
use App\Modules\Catalog\Http\Controllers\Admin\ProductController;
use App\Modules\Catalog\Http\Controllers\Admin\TaxonomyController;
use App\Modules\Catalog\Http\Controllers\Admin\VariantController;
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

    Route::prefix('import-export')->name('import.')->group(function (): void {
        Route::get('/', [ImportController::class, 'index'])->middleware('permission:catalog.view')->name('index');
        Route::get('/template', [ImportController::class, 'template'])->middleware('permission:catalog.view')->name('template');
        Route::get('/export', [ImportController::class, 'export'])->middleware('permission:catalog.view')->name('export');
        Route::post('/', [ImportController::class, 'store'])->middleware(['permission:catalog.create', 'throttle:10,1'])->name('store');
        Route::get('/{import}', [ImportController::class, 'show'])->middleware('permission:catalog.view')->name('show');
        Route::post('/{import}/apply', [ImportController::class, 'apply'])->middleware(['permission:catalog.create', 'throttle:10,1'])->name('apply');
    });

    Route::prefix('products')->name('products.')->group(function (): void {
        Route::get('/', [ProductController::class, 'index'])->middleware('permission:catalog.view')->name('index');
        Route::get('/create', [ProductController::class, 'create'])->middleware('permission:catalog.create')->name('create');
        Route::post('/', [ProductController::class, 'store'])->middleware('permission:catalog.create')->name('store');
        Route::get('/{product}/edit', [ProductController::class, 'edit'])->middleware('permission:catalog.view')->name('edit');
        Route::put('/{product}', [ProductController::class, 'update'])->middleware('permission:catalog.update')->name('update');
        Route::post('/{product}/transition', [ProductController::class, 'transition'])->middleware('permission:catalog.publish')->name('transition');
        Route::delete('/{product}', [ProductController::class, 'destroy'])->middleware('permission:catalog.delete')->name('destroy');

        Route::post('/{product}/media', [MediaController::class, 'store'])->middleware('permission:catalog.update')->name('media.store');
        Route::post('/{product}/media/{media}/primary', [MediaController::class, 'primary'])->middleware('permission:catalog.update')->name('media.primary');
        Route::post('/{product}/media/{media}/move', [MediaController::class, 'move'])->middleware('permission:catalog.update')->name('media.move');
        Route::delete('/{product}/media/{media}', [MediaController::class, 'destroy'])->middleware('permission:catalog.update')->name('media.destroy');

        Route::post('/{product}/variants', [VariantController::class, 'store'])->middleware('permission:catalog.update')->name('variants.store');
        Route::post('/{product}/variants/generate', [VariantController::class, 'generate'])->middleware('permission:catalog.update')->name('variants.generate');
        Route::put('/{product}/variants/{variant}', [VariantController::class, 'update'])->middleware('permission:catalog.update')->name('variants.update');
        Route::delete('/{product}/variants/{variant}', [VariantController::class, 'destroy'])->middleware('permission:catalog.update')->name('variants.destroy');
    });

    Route::prefix('attributes')->name('attributes.')->group(function (): void {
        Route::get('/', [AttributeController::class, 'index'])->middleware('permission:catalog.view')->name('index');
        Route::get('/create', [AttributeController::class, 'create'])->middleware('permission:catalog.create')->name('create');
        Route::post('/', [AttributeController::class, 'store'])->middleware('permission:catalog.create')->name('store');
        Route::get('/{attribute}/edit', [AttributeController::class, 'edit'])->middleware('permission:catalog.update')->name('edit');
        Route::put('/{attribute}', [AttributeController::class, 'update'])->middleware('permission:catalog.update')->name('update');
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
