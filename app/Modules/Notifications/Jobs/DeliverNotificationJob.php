<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Actions\DeliverNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Delivers one recorded message as soon as it has been recorded. If it fails, the record's own schedule (and the every-minute sweep) tries again. */
class DeliverNotificationJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $logId) {}

    public function handle(DeliverNotification $deliver): void
    {
        $deliver->handle($this->logId);
    }
}
