<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;

/** The landing page of the operations screens: health, scheduler, failed jobs and data-integrity checks. Each card shows only if the person may open it. */
class OpsController
{
    public function index(): View
    {
        return view('platform::admin.ops.index');
    }
}
