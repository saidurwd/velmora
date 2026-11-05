<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Actions\DeliverDueNotifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Every minute: deliver what is due. */
class RunNotificationSweepJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(DeliverDueNotifications $due): void
    {
        $due->handle();
    }
}
