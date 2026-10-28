<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;

class DashboardController
{
    public function __invoke(): View
    {
        return view('platform::admin.dashboard');
    }
}
