<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Http\Controllers\Api\AuthController;
use App\Modules\IdentityAccess\Http\Controllers\Api\MeController;
use App\Modules\IdentityAccess\Http\Controllers\Api\SessionController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 with the `api.v1.` name prefix. Customers only: staff never sign in through the app.
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::get('/signup-policies', [AuthController::class, 'signupPolicies'])->middleware('throttle:public-read')->name('policies');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth')->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth')->name('login');
    Route::post('/otp/request', [AuthController::class, 'otpRequest'])->middleware('throttle:otp')->name('otp.request');
    Route::post('/otp/verify', [AuthController::class, 'otpVerify'])->middleware('throttle:auth')->name('otp.verify');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth')->name('refresh');
    Route::post('/password/forgot', [AuthController::class, 'forgot'])->middleware('throttle:auth')->name('password.forgot');
    Route::post('/password/reset', [AuthController::class, 'reset'])->middleware('throttle:auth')->name('password.reset');

    Route::middleware(['auth:sanctum', 'api.customer'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('throttle:write')->name('logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->middleware('throttle:write')->name('logout-all');
        Route::post('/reauth/code', [AuthController::class, 'reauthCode'])->middleware('throttle:otp')->name('reauth.code');
        Route::post('/reauth', [AuthController::class, 'reauth'])->middleware('throttle:auth')->name('reauth');
    });
});

Route::middleware(['auth:sanctum', 'api.customer'])->group(function (): void {
    Route::get('/me', [MeController::class, 'show'])->middleware('throttle:user-read')->name('me');
    Route::post('/me/password', [MeController::class, 'changePassword'])->middleware('throttle:write')->name('me.password');
    Route::get('/me/tokens', [SessionController::class, 'index'])->middleware('throttle:user-read')->name('me.tokens');
    Route::delete('/me/tokens/{id}', [SessionController::class, 'destroy'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->middleware('throttle:write')->name('me.tokens.destroy');
});
