<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Reporting\Exceptions\ReportException;
use Illuminate\Support\Facades\DB;

/**
 * Hands a finished export to the person who asked for it, and to nobody else (someone else's export is "not found"). The file must be ready and not expired, and the person must
 * STILL be allowed what the file holds: a file built with finance columns or personal data is refused to someone who has since lost that right. Every download is audited.
 */
final class DownloadReportExport
{
    public function __construct(private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /**
     * @return array{key: string, name: string}
     *
     * @throws ReportException not_found | not_ready | expired | forbidden
     */
    public function handle(Actor $actor, bool $canFinance, bool $canPii, string $publicId): array
    {
        $row = DB::table('report_exports')->where('public_id', $publicId)->where('requested_by', $actor->id)->first();   // unique public_id
        if ($row === null) {
            throw new ReportException('not_found');
        }
        if ($row->status === 'expired' || ($row->status === 'ready' && $row->expires_at <= $this->clock->now()->format('Y-m-d H:i:s.u'))) {
            throw new ReportException('expired');
        }
        if ($row->status !== 'ready' || $row->file_key === null) {
            throw new ReportException('not_ready');
        }
        if (($row->with_finance && ! $canFinance) || ($row->with_pii && ! $canPii)) {
            throw new ReportException('forbidden');
        }

        $now = $this->clock->now();
        DB::table('report_exports')->where('id', $row->id)->update(['downloads' => DB::raw('downloads + 1'), 'last_downloaded_at' => $now]);
        $this->audit->record('reports.export_downloaded', 'report_export', [], ['report' => $row->report_code], $actor, (int) $row->id);
        /** @var array<string, string> $f */
        $f = json_decode((string) $row->filters, true, flags: JSON_THROW_ON_ERROR);

        return ['key' => (string) $row->file_key, 'name' => preg_replace('/[^a-z0-9_-]/i', '', $row->report_code.'-'.($f['from'] ?? '').'-'.($f['to'] ?? '')).'.csv'];
    }
}
