<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\Web\HealthController;
use App\Modules\Platform\Http\Controllers\Web\LocaleController;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', [HealthController::class, 'live'])->name('health.live');
Route::get('/readyz', [HealthController::class, 'ready'])->name('health.ready');

// "/" -> best locale; "/en" and "/bn" are the real homes.
Route::get('/', [LocaleController::class, 'root'])->name('root');

Route::localized(function (): void {
    if (! app()->isProduction()) {
        Route::view('/dev/components', 'platform::dev.components')->name('dev.components'); // component library, never served in production
    }
});

// Unmatched GETs still run the web middleware (locale, session) so 404 pages render in the visitor's language.
Route::fallback(fn () => abort(404))->middleware('web');

// Language choice for pages that are not locale-prefixed (admin, account settings).
Route::post('/{locale}/locale', [LocaleController::class, 'set'])
    ->whereIn('locale', config('app.supported_locales'))
    ->middleware('throttle:write')
    ->name('locale.set');
