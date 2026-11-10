<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\CancellationFigures;
use App\Modules\Platform\DTOs\ReasonFigure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseCancellationFigures implements CancellationFigures
{
    public function cancelledBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Index order_status_history (axis, to_state, created_at): the period's cancellations, then a small group by. An order is cancelled once, so one history row per order.
        $rows = DB::table('order_status_history as h')->join('orders as o', 'o.id', '=', 'h.order_id')
            ->where('h.axis', 'status')->where('h.to_state', 'cancelled')
            ->where('h.created_at', '>=', $from->utc()->format('Y-m-d H:i:s.u'))->where('h.created_at', '<', $to->utc()->format('Y-m-d H:i:s.u'))
            ->groupBy('h.reason_code', 'o.currency')->orderBy('h.reason_code')->orderBy('o.currency')
            ->selectRaw('h.reason_code, o.currency, COUNT(*) AS n, COALESCE(SUM(o.grand_total_minor), 0) AS amount')->get();

        return array_values($rows->map(fn ($r): ReasonFigure => new ReasonFigure($r->reason_code === null ? null : (string) $r->reason_code, (string) $r->currency, (int) $r->n, (int) $r->amount))->all());
    }
}
