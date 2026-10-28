<?php

declare(strict_types=1);

namespace App\Modules\Search\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Search logs are only useful while fresh: keep 90 days. */
class PruneSearchQueries implements ShouldQueue
{
    use Queueable;

    public const KEEP_DAYS = 90;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        DB::table('search_queries')->where('created_at', '<', now('UTC')->subDays(self::KEEP_DAYS))->delete();
    }
}
