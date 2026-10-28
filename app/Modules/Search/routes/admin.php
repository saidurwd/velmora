<?php

declare(strict_types=1);

use App\Modules\Search\Http\Controllers\Admin\SynonymController;
use App\Modules\Search\Http\Controllers\Admin\ZeroResultController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix.
Route::middleware(['auth:admin', 'session.policy:admin'])->prefix('search')->name('search.')->group(function (): void {
    Route::get('/synonyms', [SynonymController::class, 'index'])->middleware('permission:catalog.view')->name('synonyms.index');
    Route::post('/synonyms', [SynonymController::class, 'store'])->middleware('permission:catalog.update')->name('synonyms.store');
    Route::delete('/synonyms/{synonym}', [SynonymController::class, 'destroy'])->whereNumber('synonym')->middleware('permission:catalog.update')->name('synonyms.destroy');
    Route::get('/zero-results', [ZeroResultController::class, 'index'])->middleware('permission:catalog.view')->name('zero.index');
});
