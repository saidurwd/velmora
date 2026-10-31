<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Jobs;

use App\Modules\Shipping\Actions\PollCourierShipments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Every ten minutes: ask the couriers about parcels that are still on their way. */
class PollCourierShipmentsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(PollCourierShipments $poll): void
    {
        $poll->handle();
    }
}
