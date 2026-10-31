<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Actions\PollRefunds;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Every five minutes: follow refunds that are with the gateway. */
class PollRefundsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(PollRefunds $poll): void
    {
        $poll->handle();
    }
}
