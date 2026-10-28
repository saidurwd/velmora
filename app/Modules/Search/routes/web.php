<?php

declare(strict_types=1);

use App\Modules\Search\Http\Controllers\Web\SuggestController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::get('/search/suggest', SuggestController::class)->middleware('throttle:120,1')->name('storefront.search.suggest');
});
