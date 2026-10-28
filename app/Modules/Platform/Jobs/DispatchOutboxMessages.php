<?php

declare(strict_types=1);

namespace App\Modules\Platform\Jobs;

use App\Modules\Platform\Outbox\OutboxDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchOutboxMessages implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('critical');
    }

    public function handle(OutboxDispatcher $dispatcher): void
    {
        while ($dispatcher->dispatchDue() > 0) {
            // drain; each pass claims a fresh batch
        }
    }
}
