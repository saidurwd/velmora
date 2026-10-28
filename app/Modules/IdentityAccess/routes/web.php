<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Http\Controllers\Web\AccountSecurityController;
use App\Modules\IdentityAccess\Http\Controllers\Web\AuthController;
use App\Modules\IdentityAccess\Http\Controllers\Web\EmailVerificationController;
use App\Modules\IdentityAccess\Http\Controllers\Web\PasswordResetController;
use App\Modules\IdentityAccess\Http\Controllers\Web\PhoneAuthController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::middleware('guest:web')->group(function (): void {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');

        Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
        Route::get('/register/check-your-email', [AuthController::class, 'registered'])->name('register.sent');

        Route::get('/login/phone', [PhoneAuthController::class, 'show'])->name('login.phone');
        Route::post('/login/phone', [PhoneAuthController::class, 'send'])->middleware('throttle:otp');
        Route::get('/login/phone/verify', [PhoneAuthController::class, 'showVerify'])->name('login.phone.verify');
        Route::post('/login/phone/verify', [PhoneAuthController::class, 'verify'])->middleware('throttle:auth');

        Route::get('/forgot-password', [PasswordResetController::class, 'showForgot'])->name('password.request');
        Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:auth')->name('password.email');
        Route::get('/reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:auth')->name('password.update');
    });

    // The emailed link works whether or not the browser is signed in.
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:auth'])->name('verification.verify');

    Route::middleware(['auth:web', 'session.policy:web'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:otp')->name('verification.send');

        // Re-authentication (password, or an SMS code for phone-only accounts)
        Route::get('/account/confirm', [AccountSecurityController::class, 'showConfirm'])->name('account.confirm');
        Route::post('/account/confirm', [AccountSecurityController::class, 'confirmWithPassword'])->middleware('throttle:auth');
        Route::post('/account/confirm/send-code', [AccountSecurityController::class, 'sendConfirmOtp'])->middleware('throttle:otp')->name('account.confirm.send');
        Route::post('/account/confirm/code', [AccountSecurityController::class, 'confirmWithOtp'])->middleware('throttle:auth')->name('account.confirm.code');

        Route::post('/account/security/sessions/revoke-others', [AccountSecurityController::class, 'revokeOtherDevices'])->middleware('throttle:write')->name('account.sessions.revoke');

        // The emailed confirmation link: the owner must be signed in, the signature protects the contents
        Route::get('/account/email/confirm/{user}', [AccountSecurityController::class, 'confirmEmail'])->middleware(['signed', 'throttle:auth'])->name('account.email.confirm');

        // Sensitive changes require a confirmation within the last 10 minutes
        Route::middleware('recent.auth')->group(function (): void {
            Route::get('/account/security', [AccountSecurityController::class, 'show'])->name('account.security');
            Route::put('/account/security/password', [AccountSecurityController::class, 'changePassword'])->middleware('throttle:critical')->name('account.password.update');
            Route::post('/account/security/email', [AccountSecurityController::class, 'requestEmail'])->middleware('throttle:critical')->name('account.email.request');
            Route::post('/account/security/phone', [AccountSecurityController::class, 'requestPhone'])->middleware('throttle:otp')->name('account.phone.request');
            Route::post('/account/security/phone/confirm', [AccountSecurityController::class, 'confirmPhone'])->middleware('throttle:auth')->name('account.phone.confirm');
            Route::post('/account/delete-request', [AccountSecurityController::class, 'requestDeletion'])->middleware('throttle:critical')->name('account.delete');
        });
    });
});
