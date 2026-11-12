<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\Api\ConfigController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix.
Route::get('/config', [ConfigController::class, 'show'])->middleware(['throttle:public-read', 'etag:config'])->name('config');
