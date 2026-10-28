<?php

declare(strict_types=1);

use App\Modules\Cms\Http\Controllers\Web\PageController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::get('/pages/{slug}', [PageController::class, 'show'])->name('cms.page');
});
