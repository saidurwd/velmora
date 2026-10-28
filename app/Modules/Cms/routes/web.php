<?php

declare(strict_types=1);

use App\Modules\Cms\Http\Controllers\Web\PageController;
use App\Modules\Cms\Http\Controllers\Web\SeoFilesController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::get('/pages/{slug}', [PageController::class, 'show'])->name('cms.page');
});

Route::middleware('web')->group(function (): void {
    Route::get('/robots.txt', [SeoFilesController::class, 'robots'])->name('seo.robots');
    Route::get('/sitemap.xml', [SeoFilesController::class, 'index'])->name('seo.sitemap');
    Route::get('/sitemaps/{file}', [SeoFilesController::class, 'show'])->where('file', '[A-Za-z0-9._-]+')->name('seo.sitemap.file');
});
