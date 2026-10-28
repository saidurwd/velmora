<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Web\HomeController;
use App\Modules\Catalog\Http\Controllers\Web\ListingController;
use App\Modules\Catalog\Http\Controllers\Web\ProductPageController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::get('/', HomeController::class)->name('home');
    Route::get('/p/{slug}', [ProductPageController::class, 'show'])->name('storefront.product');
    Route::get('/search', [ListingController::class, 'search'])->middleware('throttle:60,1')->name('storefront.search');
    Route::get('/shop', [ListingController::class, 'shop'])->name('storefront.shop');
    Route::get('/c/{slug}', [ListingController::class, 'category'])->name('storefront.category');
    Route::get('/brands/{slug}', [ListingController::class, 'brand'])->name('storefront.brand');
    Route::get('/collections/{slug}', [ListingController::class, 'collection'])->name('storefront.collection');
});
