<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Exceptions\ReportException;
use App\Modules\Reporting\Jobs\BuildReportExportJob;
use App\Modules\Reporting\Reports\ReportCatalogue;
use App\Modules\Reporting\Services\ReportDays;
use App\Modules\Reporting\Services\ReportValues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A staff member asks for a report as a CSV file. This only records the request (the file is built by a worker); who may ask, and for which report, is decided by the caller.
 * What the file will hold is fixed now: the report, the choices (those the report does not have are dropped), the language of the headings, and whether the person could see
 * finance columns and personal data (a person who later loses that right can no longer download the file).
 */
final class RequestReportExport
{
    public function __construct(
        private readonly ReportCatalogue $reports,
        private readonly ReportValues $values,
        private readonly ReportDays $days,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @return string the export's public id
     *
     * @throws ReportException unknown_report | period_too_long | too_many_open
     */
    public function handle(Actor $actor, string $code, ReportFilter $filter, bool $withFinance, bool $withPii, string $locale): string
    {
        $report = $this->reports->find($code) ?? throw new ReportException('unknown_report');
        $filter = $this->values->normalise($report, $filter, $this->days->today());
        if (! $this->days->isDay($filter->from) || ! $this->days->isDay($filter->to) || $filter->to < $filter->from) {
            throw new ReportException('invalid_period');
        }
        if (count($this->days->range($filter->from, $filter->to)) > (int) config('reporting.max_range_days')) {
            throw new ReportException('period_too_long', ['days' => (int) config('reporting.max_range_days')]);
        }
        $locale = in_array($locale, (array) config('app.supported_locales'), true) ? $locale : (string) config('app.fallback_locale');

        return Transactions::run(function () use ($actor, $report, $filter, $withFinance, $withPii, $locale): string {
            // soft: two requests at the same instant can both pass (the request rate limit bounds it), which is harmless
            $open = DB::table('report_exports')->where('requested_by', $actor->id)->whereIn('status', ['requested', 'running'])->count();   // index (requested_by, id)
            if ($open >= (int) config('reporting.exports.open_max')) {
                throw new ReportException('too_many_open', ['max' => (int) config('reporting.exports.open_max')]);
            }

            $publicId = strtolower((string) Str::ulid());
            $id = DB::table('report_exports')->insertGetId([
                'public_id' => $publicId, 'requested_by' => $actor->id, 'report_code' => $report->code(), 'filters' => json_encode($filter->query(), JSON_THROW_ON_ERROR), 'locale' => $locale,
                'with_finance' => $withFinance, 'with_pii' => $withPii, 'status' => 'requested', 'requested_at' => $this->clock->now(),
            ]);
            $this->audit->record('reports.export_requested', 'report_export', [], ['report' => $report->code(), 'filters' => $filter->query(), 'finance' => $withFinance, 'pii' => $withPii], $actor, $id);
            DB::afterCommit(fn () => BuildReportExportJob::dispatch($id));

            return $publicId;
        });
    }
}
