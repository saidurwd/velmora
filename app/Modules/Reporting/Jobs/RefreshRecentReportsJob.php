<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Jobs;

use App\Modules\Reporting\Actions\RefreshReportDay;
use App\Modules\Reporting\Services\ReportDays;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Every 15 minutes: today and the day before are worked out again (a day is always replaced as a whole). */
final class RefreshRecentReportsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(RefreshReportDay $refresh, ReportDays $days): void
    {
        $today = $days->today();
        for ($back = max(1, (int) config('reporting.recent_days')) - 1; $back >= 0; $back--) {
            $refresh->handle($days->shift($today, -$back), 'schedule');
        }
    }
}
