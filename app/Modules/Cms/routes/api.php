<?php

declare(strict_types=1);

use App\Modules\Cms\Http\Controllers\Api\PageController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix. Published pages, public.
Route::get('/pages/{slug}', [PageController::class, 'show'])->middleware(['throttle:public-read', 'etag:config'])->name('pages.show');
