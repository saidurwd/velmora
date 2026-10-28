<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Outbox\OutboxDispatcher;
use Illuminate\Console\Command;

/** Drains due outbox messages. Safe to run in parallel (rows are claimed with SKIP LOCKED). */
class DispatchOutboxCommand extends Command
{
    protected $signature = 'outbox:dispatch {--limit=50 : Messages claimed per batch}';

    protected $description = 'Deliver due transactional-outbox messages';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $total = 0;

        while (($delivered = $dispatcher->dispatchDue((int) $this->option('limit'))) > 0) {
            $total += $delivered;
        }

        $this->info("Delivered {$total} message(s).");

        return self::SUCCESS;
    }
}
