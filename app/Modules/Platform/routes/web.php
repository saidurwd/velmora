<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\Web\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', [HealthController::class, 'live'])->name('health.live');
Route::get('/readyz', [HealthController::class, 'ready'])->name('health.ready');
