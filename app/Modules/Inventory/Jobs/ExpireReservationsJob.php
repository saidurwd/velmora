<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Jobs;

use App\Modules\Inventory\Actions\ExpireReservations;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireReservationsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('critical');
    }

    public function handle(ExpireReservations $expire): void
    {
        while ($expire->handle() > 0) {
            // drain batch by batch
        }
    }
}
