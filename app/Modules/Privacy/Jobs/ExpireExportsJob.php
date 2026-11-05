<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Jobs;

use App\Modules\Privacy\Actions\ExpireExports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ExpireExportsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(ExpireExports $expire): void
    {
        $expire->handle();
    }
}
