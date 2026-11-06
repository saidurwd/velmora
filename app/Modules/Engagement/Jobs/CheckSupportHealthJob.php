<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Jobs;

use App\Modules\Engagement\Actions\CheckSupportHealth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CheckSupportHealthJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(CheckSupportHealth $check): void
    {
        $check->handle();
    }
}
