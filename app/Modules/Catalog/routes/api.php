<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Api\CategoryController;
use App\Modules\Catalog\Http\Controllers\Api\ProductController;
use App\Modules\Catalog\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix. Public, read-only, cached with an ETag.
Route::middleware(['throttle:public-read', 'etag:catalog'])->group(function (): void {
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories');
    Route::get('/categories/{slug}', [CategoryController::class, 'show'])->name('categories.show');
    Route::get('/brands', [CategoryController::class, 'brands'])->name('brands');
    Route::get('/collections/{slug}', [CategoryController::class, 'collection'])->name('collections.show');
    Route::get('/products', [ProductController::class, 'index'])->name('products');
    Route::get('/products/{slug}', [ProductController::class, 'show'])->name('products.show');
    Route::get('/products/{slug}/related', [ProductController::class, 'related'])->name('products.related');
});

Route::middleware(['throttle:search', 'etag:catalog'])->group(function (): void {
    Route::get('/search', [SearchController::class, 'search'])->name('search');
    Route::get('/search/autocomplete', [SearchController::class, 'autocomplete'])->name('search.autocomplete');
});
