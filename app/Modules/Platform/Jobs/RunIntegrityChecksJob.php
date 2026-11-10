<?php

declare(strict_types=1);

namespace App\Modules\Platform\Jobs;

use App\Modules\Platform\Actions\RunIntegrityChecks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Every night: all data-integrity checks run and their last results are kept for the staff page. Read-only. */
final class RunIntegrityChecksJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(RunIntegrityChecks $run): void
    {
        $run->handle();
    }
}
