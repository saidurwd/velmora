<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Modules\Payments\Services\FinanceToday;
use App\Modules\Payments\Services\PaymentHealth;
use Illuminate\Contracts\View\View;

class TodayController
{
    public function index(FinanceToday $today, PaymentHealth $health): View
    {
        return view('payments::admin.today', ['report' => $today->report(), 'alerts' => array_filter($health->snapshot(), fn (array $s): bool => $s['breached'])]);
    }
}
