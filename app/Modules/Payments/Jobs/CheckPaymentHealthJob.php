<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Actions\CheckPaymentHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Every five minutes: look at the payment signals and log the ones that are breached. */
class CheckPaymentHealthJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(CheckPaymentHealth $check): void
    {
        $check->handle();
    }
}
