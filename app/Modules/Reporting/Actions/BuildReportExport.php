<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\CsvWriter;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Exceptions\ReportException;
use App\Modules\Reporting\Reports\ReportCatalogue;
use App\Modules\Reporting\Services\ReportValues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds the file of one requested export. It claims the export with one conditional update (requested -> running), so two workers can never build the same file; the rows
 * are written one at a time to a temporary file (a spreadsheet-friendly CSV: UTF-8 with a byte-order mark, formulas neutralised), then stored on the private disk under a random
 * name. Anything that goes wrong is recorded as a word (not the exception text) and leaves no file behind.
 */
final class BuildReportExport
{
    public function __construct(private readonly ReportCatalogue $reports, private readonly ReportValues $values, private readonly Clock $clock) {}

    public function handle(int $exportId): void
    {
        $now = $this->clock->now();
        if (DB::table('report_exports')->where('id', $exportId)->where('status', 'requested')->update(['status' => 'running', 'started_at' => $now]) !== 1) {   // primary key
            return;   // another worker has it, or it is not waiting
        }
        $export = DB::table('report_exports')->where('id', $exportId)->first();
        if ($export === null) {
            return;
        }

        $key = null;
        $previousLocale = app()->getLocale();
        $stream = null;
        try {
            app()->setLocale((string) $export->locale);
            $report = $this->reports->find((string) $export->report_code) ?? throw new ReportException('unknown_report');
            $filter = $this->filterOf($export->filters);
            $columns = $this->values->visibleColumns($report, (bool) $export->with_finance);
            $numeric = array_keys(array_filter($columns, fn ($c): bool => $this->values->isNumeric($c)));

            $stream = fopen('php://temp/maxmemory:8388608', 'w+b') ?: throw new ReportException('failed');
            CsvWriter::bom($stream);
            CsvWriter::row($stream, array_map(fn ($c): string => $this->values->label($report, $c->key), $columns));
            $rows = 0;
            foreach ($report->rows($filter) as $row) {
                if (++$rows > (int) config('reporting.exports.max_rows')) {
                    throw new ReportException('too_many_rows');
                }
                CsvWriter::row($stream, array_map(fn ($c): string => $this->values->forFile($c, $row[$c->key] ?? null, (bool) $export->with_pii), $columns), $numeric);
            }
            $size = (int) (fstat($stream)['size'] ?? 0);
            rewind($stream);
            $key = 'reports/exports/'.bin2hex(random_bytes(16)).'.csv';
            Storage::disk((string) config('reporting.exports.disk'))->put($key, $stream) ?: throw new ReportException('failed');

            $done = $this->clock->now();
            $moved = DB::table('report_exports')->where('id', $exportId)->where('status', 'running')->update([
                'status' => 'ready', 'row_count' => $rows, 'size_bytes' => $size, 'file_key' => $key, 'finished_at' => $done, 'expires_at' => $done->addDays(max(1, (int) config('reporting.exports.expires_days'))),
            ]);
            if ($moved !== 1) {
                Storage::disk((string) config('reporting.exports.disk'))->delete($key);   // it was failed meanwhile (stale): no file without a row that points at it
            }
        } catch (Throwable $e) {
            if ($key !== null) {
                Storage::disk((string) config('reporting.exports.disk'))->delete($key);
            }
            $reason = $e instanceof ReportException ? $e->reason : 'failed';
            DB::table('report_exports')->where('id', $exportId)->where('status', 'running')->update(['status' => 'failed', 'error_code' => $reason, 'finished_at' => $this->clock->now()]);
            Log::warning('reports.export_failed', ['export' => $exportId, 'reason' => $reason, 'error' => $e::class]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            app()->setLocale($previousLocale);
        }
    }

    private function filterOf(mixed $json): ReportFilter
    {
        /** @var array{from: string, to: string, channel?: string, method?: string, locale?: string} $f */
        $f = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);

        return new ReportFilter($f['from'], $f['to'], $f['channel'] ?? null, $f['method'] ?? null, $f['locale'] ?? null);
    }
}
