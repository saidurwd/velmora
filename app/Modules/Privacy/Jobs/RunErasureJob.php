<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Jobs;

use App\Modules\Privacy\Actions\RunErasure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunErasureJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly int $requestId)
    {
        $this->onQueue('low');
    }

    public function handle(RunErasure $run): void
    {
        $run->handle($this->requestId);
    }
}
