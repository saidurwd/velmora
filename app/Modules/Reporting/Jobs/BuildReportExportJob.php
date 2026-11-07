<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Jobs;

use App\Modules\Reporting\Actions\BuildReportExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class BuildReportExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;   // a failed build is recorded as failed; the person asks again

    public int $timeout = 900;

    public function __construct(public readonly int $exportId)
    {
        $this->onQueue('low');
    }

    public function handle(BuildReportExport $build): void
    {
        $build->handle($this->exportId);
    }
}
