<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\CustomerOrderMonths;
use App\Modules\Orders\DTOs\CustomerMonth;
use Illuminate\Support\Facades\DB;

final class DatabaseCustomerOrderMonths implements CustomerOrderMonths
{
    public function perCustomerMonth(string $currency, int $utcOffsetSeconds): iterable
    {
        // Every signed-in order once: this is the nightly cohort rebuild, never a screen. It groups on user_id (index user_id, created_at) and reads each row.
        $rows = DB::table('orders')->whereNotNull('user_id')->whereNotNull('placed_at')->where('status', '<>', 'cancelled')->where('currency', $currency)
            ->selectRaw("user_id, DATE_FORMAT(DATE_ADD(placed_at, INTERVAL ? SECOND), '%Y-%m') AS ym, COUNT(*) AS order_count, SUM(subtotal_minor - discount_minor) AS sales", [$utcOffsetSeconds])
            ->groupBy('user_id', 'ym')->orderBy('user_id')->orderBy('ym');

        foreach ($rows->cursor() as $r) {
            yield new CustomerMonth((int) $r->user_id, (string) $r->ym, (int) $r->order_count, (int) $r->sales);
        }
    }
}
