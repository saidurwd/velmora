<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Modules\Payments\Services\FinanceToday;
use Illuminate\Contracts\View\View;

class TodayController
{
    public function index(FinanceToday $today): View
    {
        return view('payments::admin.today', ['report' => $today->report()]);
    }
}
