<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Providers;

use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Reporting\Console\BuildExport;
use App\Modules\Reporting\Console\RebuildReports;
use App\Modules\Reporting\Reports\ReportCatalogue;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ReportingServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Reporting';
    }

    public function register(): void
    {
        $this->app->singleton(ReportCatalogue::class);
    }

    public function boot(): void
    {
        parent::boot();

        RateLimiter::for('report-export', fn (Request $r) => Limit::perHour(10)->by('report-export|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));
        RateLimiter::for('reports', fn (Request $r) => Limit::perMinute(60)->by('reports|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));
        $menu = $this->app->make(AdminMenu::class);
        $menu->add('reporting::reports.menu', 'admin.reports.sales', 'reports.view', 20);
        $menu->add('reporting::reports.menu_all', 'admin.reports.index', 'reports.view', 21);
        $menu->add('reporting::exports.menu', 'admin.reports.exports', 'reports.export', 22);
        $this->commands([RebuildReports::class, BuildExport::class]);
    }
}
