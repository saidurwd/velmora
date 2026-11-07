<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Jobs;

use App\Modules\Reporting\Actions\RefreshReportDay;
use App\Modules\Reporting\Services\ReportDays;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Every night: the last `reporting.window_days` days are worked out again, so a cancellation or refund that came late lands on the day it belongs to. Older days are left as they are. */
final class RefreshWindowReportsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(RefreshReportDay $refresh, ReportDays $days): void
    {
        $today = $days->today();
        foreach ($days->range($days->shift($today, -(max(1, (int) config('reporting.window_days')) - 1)), $today) as $day) {
            $refresh->handle($day, 'nightly');
        }
    }
}
