<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Hourly: finished files past their date are deleted (the export becomes `expired`), and exports stuck waiting or running too long are marked failed (the worker died). */
final class ExpireReportExports
{
    public function __construct(private readonly Clock $clock) {}

    /** @return array{expired: int, stale: int} */
    public function handle(): array
    {
        $now = $this->clock->now();
        $disk = Storage::disk((string) config('reporting.exports.disk'));

        $expired = 0;
        foreach (DB::table('report_exports')->where('status', 'ready')->where('expires_at', '<=', $now)->limit(500)->get(['id', 'file_key']) as $row) {   // index (status, expires_at)
            if (DB::table('report_exports')->where('id', $row->id)->where('status', 'ready')->update(['status' => 'expired', 'file_key' => null]) === 1) {
                if ($row->file_key !== null) {
                    $disk->delete((string) $row->file_key);
                }
                $expired++;
            }
        }

        $cutoff = $now->subMinutes(max(1, (int) config('reporting.exports.stale_minutes')));
        $stale = DB::table('report_exports')->where('status', 'running')->where('started_at', '<', $cutoff)->update(['status' => 'failed', 'error_code' => 'stale', 'finished_at' => $now])
            + DB::table('report_exports')->where('status', 'requested')->where('requested_at', '<', $cutoff)->update(['status' => 'failed', 'error_code' => 'stale', 'finished_at' => $now]);

        return ['expired' => $expired, 'stale' => $stale];
    }
}
