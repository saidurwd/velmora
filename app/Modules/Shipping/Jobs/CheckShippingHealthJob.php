<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Jobs;

use App\Modules\Shipping\Actions\CheckShippingHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Every fifteen minutes: log the delivery signals that need a person. */
class CheckShippingHealthJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(CheckShippingHealth $check): void
    {
        $check->handle();
    }
}
