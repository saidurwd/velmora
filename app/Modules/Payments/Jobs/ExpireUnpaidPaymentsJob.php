<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Actions\ExpireUnpaidPayments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Every minute: close online payments that ran out of time. */
class ExpireUnpaidPaymentsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(ExpireUnpaidPayments $expire): void
    {
        $expire->handle();
    }
}
