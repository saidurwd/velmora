<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Web\HomeController;
use App\Modules\Catalog\Http\Controllers\Web\ListingController;
use App\Modules\Catalog\Http\Controllers\Web\ProductPageController;
use Illuminate\Support\Facades\Route;

// Public pages revalidate with an ETag: unchanged pages answer 304 with no body (cheap on slow mobile links).
Route::middleware('cache.headers:private;max_age=0;must_revalidate;etag')->group(function (): void {
    Route::localized(function (): void {
        Route::get('/', HomeController::class)->name('home');
        Route::get('/p/{slug}', [ProductPageController::class, 'show'])->name('storefront.product');
        Route::get('/search', [ListingController::class, 'search'])->middleware('throttle:60,1')->name('storefront.search');
        Route::get('/shop', [ListingController::class, 'shop'])->name('storefront.shop');
        Route::get('/c/{slug}', [ListingController::class, 'category'])->name('storefront.category');
        Route::get('/brands/{slug}', [ListingController::class, 'brand'])->name('storefront.brand');
        Route::get('/collections/{slug}', [ListingController::class, 'collection'])->name('storefront.collection');
    });
});
