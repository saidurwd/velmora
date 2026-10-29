<?php

declare(strict_types=1);

use App\Modules\Analytics\Http\Controllers\Web\EventController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::post('/analytics/events', EventController::class)->middleware('throttle:120,1')->name('analytics.events');
});
