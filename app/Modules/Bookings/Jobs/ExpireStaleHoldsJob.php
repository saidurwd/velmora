<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Jobs;

use App\Modules\Bookings\Actions\ExpireStaleHolds;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Every minute: give back the time of holds that ran out. */
class ExpireStaleHoldsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(ExpireStaleHolds $expire): void
    {
        $expire->handle();
    }
}
