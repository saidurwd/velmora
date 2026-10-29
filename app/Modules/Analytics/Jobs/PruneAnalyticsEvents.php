<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Raw events are kept 90 days; longer-term numbers belong in aggregated reports (Phase 10). */
class PruneAnalyticsEvents implements ShouldQueue
{
    use Queueable;

    public const KEEP_DAYS = 90;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        DB::table('analytics_events')->where('created_at', '<', now('UTC')->subDays(self::KEEP_DAYS))->delete();
    }
}
