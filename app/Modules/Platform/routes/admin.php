<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\Admin\AuditController;
use App\Modules\Platform\Http\Controllers\Admin\BrandingController;
use App\Modules\Platform\Http\Controllers\Admin\DashboardController;
use App\Modules\Platform\Http\Controllers\Admin\FailedJobController;
use App\Modules\Platform\Http\Controllers\Admin\FeatureFlagController;
use App\Modules\Platform\Http\Controllers\Admin\IntegrationsController;
use App\Modules\Platform\Http\Controllers\Admin\IntegrityController;
use App\Modules\Platform\Http\Controllers\Admin\MaintenanceController;
use App\Modules\Platform\Http\Controllers\Admin\OpsController;
use App\Modules\Platform\Http\Controllers\Admin\SchedulerController;
use App\Modules\Platform\Http\Controllers\Admin\SettingsController;
use App\Modules\Platform\Http\Controllers\Admin\SystemHealthController;
use App\Modules\Platform\Support\FeatureFlags;
use App\Modules\Platform\Support\IntegrationCatalogue;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:admin', 'session.policy:admin'])->group(function (): void {
    Route::get('/settings/branding', [BrandingController::class, 'edit'])->middleware('permission:settings.view')->name('settings.branding');
    Route::put('/settings/branding', [BrandingController::class, 'update'])->middleware('permission:settings.manage')->name('settings.branding.update');
    Route::get('/settings', [SettingsController::class, 'index'])->middleware('permission:settings.view')->name('settings.index');
    Route::get('/settings/business', [SettingsController::class, 'business'])->middleware('permission:settings.view')->name('settings.business');
    Route::put('/settings/business', [SettingsController::class, 'updateBusiness'])->middleware(['permission:settings.manage', 'throttle:30,1,settings-write'])->name('settings.business.update');
    Route::get('/settings/tax', [SettingsController::class, 'tax'])->middleware('permission:settings.view')->name('settings.tax');
    Route::put('/settings/tax', [SettingsController::class, 'updateTax'])->middleware(['permission:settings.manage', 'throttle:30,1,settings-write'])->name('settings.tax.update');
    Route::get('/settings/locale', [SettingsController::class, 'locale'])->middleware('permission:settings.view')->name('settings.locale');

    Route::get('/settings/integrations', [IntegrationsController::class, 'index'])->middleware('permission:integrations.manage')->name('integrations.index');
    Route::put('/settings/integrations/{provider}', [IntegrationsController::class, 'update'])->where('provider', implode('|', IntegrationCatalogue::PROVIDERS))->middleware(['permission:integrations.manage', 'throttle:10,1,integrations-write'])->name('integrations.update');
    Route::post('/settings/integrations/{provider}/test', [IntegrationsController::class, 'test'])->where('provider', implode('|', IntegrationCatalogue::PROVIDERS))->middleware(['permission:integrations.manage', 'throttle:6,1,integrations-test'])->name('integrations.test');

    Route::get('/features', [FeatureFlagController::class, 'index'])->middleware('permission:settings.view')->name('features.index');
    Route::put('/features/{flag}', [FeatureFlagController::class, 'update'])->where('flag', implode('|', FeatureFlags::names()))->middleware(['permission:features.manage', 'throttle:30,1,features-write'])->name('features.update');

    Route::get('/maintenance', [MaintenanceController::class, 'show'])->middleware('permission:settings.view')->name('maintenance.show');
    Route::post('/maintenance/enable', [MaintenanceController::class, 'enable'])->middleware(['permission:settings.manage', 'throttle:6,1,maintenance'])->name('maintenance.enable');
    Route::post('/maintenance/disable', [MaintenanceController::class, 'disable'])->middleware(['permission:settings.manage', 'throttle:6,1,maintenance'])->name('maintenance.disable');

    Route::get('/audit', [AuditController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');
    Route::get('/audit/export', [AuditController::class, 'export'])->middleware(['permission:audit.view', 'throttle:6,1,audit-export'])->name('audit.export');
    Route::get('/audit/{event}', [AuditController::class, 'show'])->whereNumber('event')->middleware('permission:audit.view')->name('audit.show');

    Route::get('/ops', [OpsController::class, 'index'])->middleware('permission:system.view,queues.view')->name('ops.index');
    Route::get('/ops/health', [SystemHealthController::class, 'index'])->middleware('permission:system.view')->name('ops.health');
    Route::get('/ops/scheduler', [SchedulerController::class, 'index'])->middleware('permission:system.view')->name('ops.scheduler');
    Route::get('/ops/integrity', [IntegrityController::class, 'index'])->middleware('permission:system.view')->name('ops.integrity');
    Route::post('/ops/integrity/run', [IntegrityController::class, 'run'])->middleware(['permission:system.view', 'throttle:6,1,ops-integrity'])->name('ops.integrity.run');
    Route::get('/ops/failed-jobs', [FailedJobController::class, 'index'])->middleware('permission:queues.view')->name('ops.failed');
    Route::post('/ops/failed-jobs/{failed}/retry', [FailedJobController::class, 'retry'])->whereNumber('failed')->middleware(['permission:queues.manage', 'throttle:30,1,ops-failed-write'])->name('ops.failed.retry');
    Route::post('/ops/failed-jobs/{failed}/forget', [FailedJobController::class, 'forget'])->whereNumber('failed')->middleware(['permission:queues.manage', 'throttle:30,1,ops-failed-write'])->name('ops.failed.forget');

    Route::get('/', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');
});
