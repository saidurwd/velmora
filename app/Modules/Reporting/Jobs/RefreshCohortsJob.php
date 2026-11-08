<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Jobs;

use App\Modules\Reporting\Actions\RefreshCohorts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Every night: the customer cohorts are worked out again from the order history. */
final class RefreshCohortsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(RefreshCohorts $refresh): void
    {
        $refresh->handle();
    }
}
