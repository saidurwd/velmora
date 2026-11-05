<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Jobs;

use App\Modules\Privacy\Actions\BuildExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class BuildExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $requestId)
    {
        $this->onQueue('low');
    }

    public function handle(BuildExport $build): void
    {
        $build->handle($this->requestId);
    }
}
