<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Actions\ReconcilePendingPayments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Every ten minutes: ask the gateway about online attempts that are still unfinished. */
class ReconcilePendingPaymentsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(ReconcilePendingPayments $reconcile): void
    {
        $reconcile->handle();
    }
}
