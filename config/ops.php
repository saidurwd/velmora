<?php

declare(strict_types=1);
use App\Modules\Analytics\Jobs\PruneAnalyticsEvents;
use App\Modules\Cart\Jobs\PurgeExpiredCarts;
use App\Modules\Catalog\Jobs\ReconcileListing;
use App\Modules\Cms\Jobs\GenerateSitemaps;
use App\Modules\Cms\Jobs\PublishDuePagesJob;
use App\Modules\IdentityAccess\Jobs\PurgeExpiredOtpChallenges;
use App\Modules\Inventory\Jobs\VerifyStockLedger;
use App\Modules\Platform\Jobs\DispatchOutboxMessages;
use App\Modules\Privacy\Jobs\ExpireExportsJob;
use App\Modules\Reporting\Jobs\ExpireReportExportsJob;
use App\Modules\Reporting\Jobs\RefreshCohortsJob;
use App\Modules\Reporting\Jobs\RefreshRecentReportsJob;
use App\Modules\Reporting\Jobs\RefreshWindowReportsJob;
use App\Modules\Search\Jobs\PruneSearchQueries;

return [
    // The queues the system health page counts (the names jobs are put on).
    'queues' => ['default', 'low'],

    /*
    | Failed jobs staff may send back to the queue. Only jobs that are safe to run again: they work out the same result a second time
    | (a refresh, a clean-up, a read-only check). Anything that sends a message, moves money or changes an order is NOT on this list; it can be
    | looked at and forgotten, never retried from the screen.
    */
    'retryable_jobs' => [
        RefreshRecentReportsJob::class,
        RefreshWindowReportsJob::class,
        RefreshCohortsJob::class,
        ExpireReportExportsJob::class,
        PublishDuePagesJob::class,
        GenerateSitemaps::class,
        ReconcileListing::class,
        PruneSearchQueries::class,
        PruneAnalyticsEvents::class,
        ExpireExportsJob::class,
        PurgeExpiredCarts::class,
        PurgeExpiredOtpChallenges::class,
        VerifyStockLedger::class,
        DispatchOutboxMessages::class,
    ],

    'integrity' => [
        'sample' => 10,                                             // how many examples a check keeps
        'max_problems' => 1000,                                     // a check stops counting here ("at least")
        'window_days' => (int) env('OPS_INTEGRITY_WINDOW_DAYS', 90), // checks over append-only records look at recent ones only
        'stuck_outbox_minutes' => 10,                               // a message waiting longer than this is stuck
    ],

    'scheduler' => [
        'late_after_seconds' => 120,                                // a task whose last start is this far behind its schedule is flagged
    ],
];
