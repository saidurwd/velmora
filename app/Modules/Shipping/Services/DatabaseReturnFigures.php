<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Shipping\Contracts\ReturnFigures;
use App\Modules\Shipping\DTOs\ReturnReasonRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseReturnFigures implements ReturnFigures
{
    public function requestedBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->utc()->format('Y-m-d H:i:s.u');
        $end = $to->utc()->format('Y-m-d H:i:s.u');

        // Index return_requests (created_at, kind, reason_code): the period's requests, then a small group by.
        $requests = DB::table('return_requests')->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->groupBy('kind', 'reason_code')->orderBy('kind')->orderBy('reason_code')
            ->selectRaw(
                'kind, reason_code, COUNT(*) AS requests, '
                ."COALESCE(SUM(status IN ('authorized','received','closed')), 0) AS approved, "
                ."COALESCE(SUM(status = 'rejected'), 0) AS rejected, "
                ."COALESCE(SUM(status IN ('received','closed')), 0) AS received",
            )->get();

        // The units asked for, through return_items (return_id); kept apart so a request with several lines is still counted once above.
        $units = DB::table('return_items as i')->join('return_requests as r', 'r.id', '=', 'i.return_id')
            ->where('r.created_at', '>=', $start)->where('r.created_at', '<', $end)
            ->groupBy('r.kind', 'r.reason_code')->selectRaw('r.kind, r.reason_code, SUM(i.quantity) AS units')->get();
        $unitsBy = [];
        foreach ($units as $u) {
            $unitsBy[$u->kind.'|'.$u->reason_code] = (int) $u->units;
        }

        return array_values($requests->map(fn ($r): ReturnReasonRow => new ReturnReasonRow(
            (string) $r->kind, (string) $r->reason_code, (int) $r->requests, $unitsBy[$r->kind.'|'.$r->reason_code] ?? 0, (int) $r->approved, (int) $r->rejected, (int) $r->received,
        ))->all());
    }
}
