<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Http\Controllers\Admin\AdminAuthController;
use App\Modules\IdentityAccess\Http\Controllers\Admin\AdminPasswordController;
use App\Modules\IdentityAccess\Http\Controllers\Admin\MfaController;
use App\Modules\IdentityAccess\Http\Controllers\Admin\RoleController;
use App\Modules\IdentityAccess\Http\Controllers\Admin\StaffController;
use App\Modules\IdentityAccess\Http\Controllers\Admin\StaffSessionController;
use Illuminate\Support\Facades\Route;

// Mounted under /admin with the `admin.` name prefix (see ModuleServiceProvider).

Route::middleware('guest:admin')->group(function (): void {
    Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:auth');

    Route::get('/forgot-password', [AdminPasswordController::class, 'showForgot'])->name('password.request');
    Route::post('/forgot-password', [AdminPasswordController::class, 'sendLink'])->middleware('throttle:auth')->name('password.email');
    Route::get('/reset-password/{token}', [AdminPasswordController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [AdminPasswordController::class, 'reset'])->middleware('throttle:auth')->name('password.update');
});

// Second factor: only after a correct password (pending state in the session, 5 minutes).
Route::middleware(['guest:admin', 'admin.pending'])->prefix('mfa')->name('mfa.')->group(function (): void {
    Route::get('/enrol', [MfaController::class, 'showEnrol'])->name('enrol');
    Route::post('/enrol', [MfaController::class, 'enrol'])->middleware('throttle:auth');
    Route::get('/challenge', [MfaController::class, 'showChallenge'])->name('challenge');
    Route::post('/challenge', [MfaController::class, 'challenge'])->middleware('throttle:auth');
});

Route::middleware(['auth:admin', 'session.policy:admin'])->group(function (): void {
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
    Route::get('/mfa/recovery-codes', [MfaController::class, 'showRecovery'])->name('mfa.recovery');
    Route::get('/account/sessions', [StaffSessionController::class, 'index'])->name('sessions');
    Route::post('/account/sessions/revoke-others', [StaffSessionController::class, 'revokeOthers'])->name('sessions.revoke-others');

    // Staff administration (permission-gated; escalation rules are enforced again inside the Actions).
    Route::prefix('staff')->name('staff.')->group(function (): void {
        Route::get('/', [StaffController::class, 'index'])->middleware('permission:staff.view')->name('index');
        Route::get('/create', [StaffController::class, 'create'])->middleware('permission:staff.create')->name('create');
        Route::post('/', [StaffController::class, 'store'])->middleware('permission:staff.create')->name('store');
        Route::get('/{staff}/edit', [StaffController::class, 'edit'])->middleware('permission:staff.update')->name('edit');
        Route::put('/{staff}', [StaffController::class, 'update'])->middleware('permission:staff.update')->name('update');
        Route::post('/{staff}/disable', [StaffController::class, 'disable'])->middleware('permission:staff.disable')->name('disable');
        Route::post('/{staff}/enable', [StaffController::class, 'enable'])->middleware('permission:staff.disable')->name('enable');
    });

    Route::prefix('roles')->name('roles.')->group(function (): void {
        Route::get('/', [RoleController::class, 'index'])->middleware('permission:roles.view')->name('index');
        Route::get('/create', [RoleController::class, 'create'])->middleware('permission:roles.manage')->name('create');
        Route::post('/', [RoleController::class, 'store'])->middleware('permission:roles.manage')->name('store');
        Route::get('/{role}/edit', [RoleController::class, 'edit'])->middleware('permission:roles.view')->name('edit');
        Route::put('/{role}', [RoleController::class, 'update'])->middleware('permission:roles.manage')->name('update');
        Route::delete('/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.manage')->name('destroy');
    });
});
