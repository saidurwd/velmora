<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Jobs;

use App\Modules\DigitalProducts\Actions\CheckKeyStock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Every fifteen minutes: hand out keys that can now be handed out, and log the products that are short of keys. */
class CheckKeyStockJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(CheckKeyStock $check): void
    {
        $check->handle();
    }
}
