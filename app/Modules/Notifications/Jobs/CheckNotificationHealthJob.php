<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Actions\CheckNotificationHealth;
use App\Modules\Notifications\Actions\PurgeNotificationPayloads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Hourly: say so if messages are failing, and erase the kept addresses of messages that have been dead for the retention period. */
class CheckNotificationHealthJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(CheckNotificationHealth $health, PurgeNotificationPayloads $purge): void
    {
        $health->handle();
        $purge->handle();
    }
}
