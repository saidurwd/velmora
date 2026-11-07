<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Console;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Reporting\Actions\RefreshReportDay;
use App\Modules\Reporting\Services\ReportDays;
use Illuminate\Console\Command;

/** Works a stretch of days out again, however old. This is the only way a day outside the nightly window changes, and it is written to the audit log. */
final class RebuildReports extends Command
{
    protected $signature = 'reports:rebuild {from : first day, YYYY-MM-DD} {to : last day, YYYY-MM-DD}';

    protected $description = 'Work report days out again from the ledgers (days older than the nightly window only change this way)';

    public function handle(RefreshReportDay $refresh, ReportDays $days, AuditLogger $audit): int
    {
        $from = (string) $this->argument('from');
        $to = (string) $this->argument('to');
        if (! $days->isDay($from) || ! $days->isDay($to) || $to < $from) {
            $this->error('Give two days as YYYY-MM-DD, the first not after the second.');

            return self::INVALID;
        }
        $range = $days->range($from, $to);
        if (count($range) > (int) config('reporting.max_rebuild_days')) {
            $this->error('That is more than '.(int) config('reporting.max_rebuild_days').' days; rebuild it in parts.');

            return self::INVALID;
        }
        if ($to > $days->today()) {
            $this->error('The last day has not started yet.');

            return self::INVALID;
        }

        foreach ($range as $day) {
            $refresh->handle($day, 'rebuild');
        }
        $audit->record('reports.rebuilt', 'report', [], ['from' => $from, 'to' => $to, 'days' => count($range)], Actor::system('console'));
        $this->info('Worked out '.count($range).' day(s).');

        return self::SUCCESS;
    }
}
