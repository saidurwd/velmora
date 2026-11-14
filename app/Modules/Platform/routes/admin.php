<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\Admin\AuditController;
use App\Modules\Platform\Http\Controllers\Admin\BrandingController;
use App\Modules\Platform\Http\Controllers\Admin\BulkController;
use App\Modules\Platform\Http\Controllers\Admin\DashboardController;
use App\Modules\Platform\Http\Controllers\Admin\FailedJobController;
use App\Modules\Platform\Http\Controllers\Admin\FeatureFlagController;
use App\Modules\Platform\Http\Controllers\Admin\IntegrationsController;
use App\Modules\Platform\Http\Controllers\Admin\IntegrityController;
use App\Modules\Platform\Http\Controllers\Admin\MaintenanceController;
use App\Modules\Platform\Http\Controllers\Admin\OpsController;
use App\Modules\Platform\Http\Controllers\Admin\SavedFilterController;
use App\Modules\Platform\Http\Controllers\Admin\SchedulerController;
use App\Modules\Platform\Http\Controllers\Admin\SearchController;
use App\Modules\Platform\Http\Controllers\Admin\SecurityController;
use App\Modules\Platform\Http\Controllers\Admin\SettingsController;
use App\Modules\Platform\Http\Controllers\Admin\SystemHealthController;
use App\Modules\Platform\Http\Controllers\Admin\SystemInfoController;
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

    // Security & audit (read only): the activity log, security events and login history; "my activity" is about the person themselves
    Route::get('/security/activity', [SecurityController::class, 'activity'])->middleware('permission:audit.view')->name('security.activity');
    Route::get('/security/events', [SecurityController::class, 'events'])->middleware('permission:audit.view')->name('security.events');
    Route::get('/security/logins', [SecurityController::class, 'logins'])->middleware('permission:audit.view')->name('security.logins');
    Route::get('/account/activity', [SecurityController::class, 'myActivity'])->name('account.activity');

    Route::get('/ops', [OpsController::class, 'index'])->middleware('permission:system.view,queues.view')->name('ops.index');
    Route::get('/ops/health', [SystemHealthController::class, 'index'])->middleware('permission:system.view')->name('ops.health');
    Route::get('/ops/scheduler', [SchedulerController::class, 'index'])->middleware('permission:system.view')->name('ops.scheduler');
    Route::get('/ops/integrity', [IntegrityController::class, 'index'])->middleware('permission:system.view')->name('ops.integrity');
    Route::post('/ops/integrity/run', [IntegrityController::class, 'run'])->middleware(['permission:system.view', 'throttle:ops-integrity'])->name('ops.integrity.run');
    // reloading the address after a run, or a bookmark of it: back to the page (the run itself is a POST)
    Route::get('/ops/integrity/run', [IntegrityController::class, 'reload'])->middleware('permission:system.view')->name('ops.integrity.reload');
    Route::get('/ops/queues', [SystemInfoController::class, 'queues'])->middleware('permission:queues.view')->name('ops.queues');
    Route::get('/ops/about', [SystemInfoController::class, 'about'])->middleware('permission:system.view')->name('ops.about');
    Route::get('/ops/failed-jobs', [FailedJobController::class, 'index'])->middleware('permission:queues.view')->name('ops.failed');
    Route::post('/ops/failed-jobs/{failed}/retry', [FailedJobController::class, 'retry'])->whereNumber('failed')->middleware(['permission:queues.manage', 'throttle:30,1,ops-failed-write'])->name('ops.failed.retry');
    Route::post('/ops/failed-jobs/{failed}/forget', [FailedJobController::class, 'forget'])->whereNumber('failed')->middleware(['permission:queues.manage', 'throttle:30,1,ops-failed-write'])->name('ops.failed.forget');

    Route::get('/search', SearchController::class)->middleware(['permission:dashboard.view', 'throttle:30,1,admin-search'])->name('search');
    Route::post('/saved-filters', [SavedFilterController::class, 'store'])->middleware(['permission:dashboard.view', 'throttle:20,1,saved-filters'])->name('saved-filters.store');
    Route::delete('/saved-filters/{filter}', [SavedFilterController::class, 'destroy'])->whereAlphaNumeric('filter')->middleware(['permission:dashboard.view', 'throttle:20,1,saved-filters'])->name('saved-filters.destroy');

    // Several rows at once: the route only lets in staff who may do ANY bulk action; the action itself is checked again against its own permissions.
    Route::post('/bulk/{action}/confirm', [BulkController::class, 'confirm'])->middleware(['permission:orders.update,reviews.moderate,support.reply', 'throttle:30,1,bulk'])->name('bulk.confirm');
    Route::post('/bulk/{action}/run', [BulkController::class, 'run'])->middleware(['permission:orders.update,reviews.moderate,support.reply', 'throttle:10,1,bulk-run'])->name('bulk.run');
    Route::get('/bulk/result', [BulkController::class, 'result'])->middleware(['permission:orders.update,reviews.moderate,support.reply', 'throttle:30,1,bulk'])->name('bulk.result');

    Route::get('/', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');
});
