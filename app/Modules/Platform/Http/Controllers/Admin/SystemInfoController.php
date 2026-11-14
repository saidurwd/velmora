<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Services\AboutInfo;
use App\Modules\Platform\Services\QueueMonitor;
use Illuminate\Contracts\View\View;

/** System screens (read only): the queue monitor (queues.view) and About this installation (system.view). */
class SystemInfoController
{
    public function queues(QueueMonitor $monitor): View
    {
        return view('platform::admin.ops.queues', ['q' => $monitor->snapshot()]);
    }

    public function about(AboutInfo $about): View
    {
        return view('platform::admin.ops.about', ['a' => $about->snapshot()]);
    }
}
