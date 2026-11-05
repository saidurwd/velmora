<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Jobs;

use App\Modules\Engagement\Actions\NotifyBackInStock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class NotifyBackInStockJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(NotifyBackInStock $notify): void
    {
        $notify->handle();
    }
}
