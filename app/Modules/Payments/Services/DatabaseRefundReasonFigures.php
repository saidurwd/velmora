<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\RefundReasonFigures;
use App\Modules\Platform\DTOs\ReasonFigure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseRefundReasonFigures implements RefundReasonFigures
{
    public function succeededBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Index refunds (status, updated_at): a refund does not change once it has succeeded, so updated_at is when it succeeded.
        $rows = DB::table('refunds')->where('status', 'succeeded')
            ->where('updated_at', '>=', $from->utc()->format('Y-m-d H:i:s.u'))->where('updated_at', '<', $to->utc()->format('Y-m-d H:i:s.u'))
            ->groupBy('reason_code', 'currency')->orderBy('reason_code')->orderBy('currency')
            ->selectRaw('reason_code, currency, COUNT(*) AS n, COALESCE(SUM(amount_minor), 0) AS amount')->get();

        return array_values($rows->map(fn ($r): ReasonFigure => new ReasonFigure($r->reason_code === null ? null : (string) $r->reason_code, (string) $r->currency, (int) $r->n, (int) $r->amount))->all());
    }
}
