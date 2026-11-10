<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Services\SystemHealth;
use Illuminate\Contracts\View\View;

class SystemHealthController
{
    public function index(SystemHealth $health): View
    {
        return view('platform::admin.ops.health', ['h' => $health->snapshot()]);
    }
}
