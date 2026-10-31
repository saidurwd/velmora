<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Actions\RecordCodCollection;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** The finance page's numbers for today (the shop's day in Dhaka) and what is waiting for a person. Read-only; every number comes from the ledgers. */
final class FinanceToday
{
    public function __construct(private readonly Clock $clock, private readonly CodAging $aging) {}

    /**
     * @return array{
     *     online_paid: array{count: int, minor: int}, cod_collected: array{count: int, minor: int}, refunded: array{count: int, minor: int},
     *     refunds_waiting: int, refunds_with_gateway: int, payments_needing_review: int, open_exceptions: array<string, int>, cod: array<string, array<string, array{count: int, minor: int}>>
     * }
     */
    public function report(): array
    {
        $start = $this->clock->nowInDisplayZone()->startOfDay()->utc();
        $from = $start->format('Y-m-d H:i:s.u');
        $to = $start->addDay()->format('Y-m-d H:i:s.u');

        return [
            'online_paid' => $this->totals(DB::table('payment_transactions')->where('status', 'succeeded')->where('provider', '!=', RecordCodCollection::PROVIDER)->whereBetween('verified_at', [$from, $to])),
            'cod_collected' => $this->totals(DB::table('cod_collections')->whereBetween('collected_at', [$from, $to])),
            'refunded' => $this->totals(DB::table('refunds')->where('status', 'succeeded')->whereBetween('updated_at', [$from, $to])),
            'refunds_waiting' => DB::table('refunds')->whereIn('status', ['requested', 'approved'])->count(),
            'refunds_with_gateway' => DB::table('refunds')->where('status', 'processing')->count(),
            'payments_needing_review' => DB::table('payments')->where('status', 'needs_review')->count(),
            'open_exceptions' => DB::table('reconciliation_exceptions')->where('status', 'open')->groupBy('type')->orderBy('type')->selectRaw('type, count(*) as n')->pluck('n', 'type')->map(fn ($n): int => (int) $n)->all(),
            'cod' => $this->aging->report(),
        ];
    }

    /** @return array{count: int, minor: int} */
    private function totals(Builder $query): array
    {
        $row = $query->selectRaw('count(*) as n, coalesce(sum(amount_minor), 0) as total')->first();

        return ['count' => (int) ($row->n ?? 0), 'minor' => (int) ($row->total ?? 0)];
    }
}
