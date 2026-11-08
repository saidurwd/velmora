<?php

declare(strict_types=1);

namespace App\Modules\Cms\Jobs;

use App\Modules\Cms\Actions\PublishDuePages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Every minute: pages scheduled to go live now are published. */
final class PublishDuePagesJob implements ShouldQueue
{
    use Queueable;

    public function handle(PublishDuePages $publish): void
    {
        $publish->handle();
    }
}
