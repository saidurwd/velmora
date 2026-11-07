<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Providers;

use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Reporting\Console\RebuildReports;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ReportingServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Reporting';
    }

    public function boot(): void
    {
        parent::boot();

        RateLimiter::for('reports', fn (Request $r) => Limit::perMinute(60)->by('reports|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));
        $this->app->make(AdminMenu::class)->add('reporting::reports.menu', 'admin.reports.sales', 'reports.view', 20);
        $this->commands([RebuildReports::class]);
    }
}
