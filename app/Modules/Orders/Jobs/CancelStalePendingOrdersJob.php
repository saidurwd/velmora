<?php

declare(strict_types=1);

namespace App\Modules\Orders\Jobs;

use App\Modules\Orders\Actions\CancelStalePendingOrders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Hourly: cancel orders that were never confirmed (see the action). */
class CancelStalePendingOrdersJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(CancelStalePendingOrders $cancel): void
    {
        $cancel->handle();
    }
}
