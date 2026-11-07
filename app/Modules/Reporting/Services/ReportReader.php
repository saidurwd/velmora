<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\DTOs\MetricInputs;
use App\Modules\Reporting\DTOs\ReportFilter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Reads the summary tables (never the order or payment tables) and hands the sums to the metrics. */
final class ReportReader
{
    public function __construct(private readonly ReportDays $days) {}

    public function inputs(ReportFilter $f): MetricInputs
    {
        return $this->combine($this->sales($f)->first(), $this->payments($f)->first());
    }

    /** @return array<string, MetricInputs> day => sums, for every day in the period that has any sales or money */
    public function perDay(ReportFilter $f): array
    {
        $sales = $this->sales($f)->groupBy('day')->addSelect('day')->get()->keyBy(fn ($r) => (string) $r->day);   // unique (day, ...)
        $money = $this->payments($f)->groupBy('day')->addSelect('day')->get()->keyBy(fn ($r) => (string) $r->day);
        $out = [];
        foreach ($this->days->range($f->from, $f->to) as $day) {
            if (isset($sales[$day]) || isset($money[$day])) {
                $out[$day] = $this->combine($sales[$day] ?? null, $money[$day] ?? null);
            }
        }

        return $out;
    }

    /**
     * The same sums split by one thing: 'channel', 'payment_method' or 'locale'.
     *
     * @param  'channel'|'payment_method'|'locale'  $dimension
     * @return array<string, MetricInputs> group => sums, groups in name order
     */
    public function breakdown(ReportFilter $f, string $dimension): array
    {
        // only these three names ever reach the SQL; anything else is a bug in the caller and stops here
        $column = match ($dimension) {
            'channel' => 'channel',
            'payment_method' => 'payment_method',
            'locale' => 'locale',
        };
        $sales = $this->sales($f)->groupBy($column)->addSelect($column.' as grp')->get()->keyBy(fn ($r) => (string) $r->grp);   // unique (day, ...) range
        $money = $this->payments($f)->groupBy($column)->addSelect($column.' as grp')->get()->keyBy(fn ($r) => (string) $r->grp);
        $names = array_values(array_unique([...$sales->keys()->all(), ...$money->keys()->all()]));
        sort($names);

        $out = [];
        foreach ($names as $name) {
            $out[(string) $name] = $this->combine($sales[$name] ?? null, $money[$name] ?? null);
        }

        return $out;
    }

    /** @return array{days: int, worked_out: int, missing: list<string>, as_of: string|null} how much of the period has been worked out (days that have not started yet are not missing) */
    public function coverage(ReportFilter $f): array
    {
        $wanted = array_values(array_filter($this->days->range($f->from, $f->to), fn (string $d): bool => $d <= $this->days->today()));
        $done = DB::table('report_day_status')->whereBetween('day', [$f->from, $f->to])->get(['day', 'refreshed_at'])->keyBy(fn ($r) => (string) $r->day);   // primary key range

        return [
            'days' => count($wanted),
            'worked_out' => count(array_filter($wanted, fn (string $d): bool => isset($done[$d]))),
            'missing' => array_values(array_filter($wanted, fn (string $d): bool => ! isset($done[$d]))),
            'as_of' => $done->isEmpty() ? null : (string) $done->max('refreshed_at'),
        ];
    }

    /** @return list<string> the payment methods that have appeared in the reports */
    public function methods(): array
    {
        return array_values(array_unique([
            ...DB::table('report_daily_sales')->distinct()->orderBy('payment_method')->pluck('payment_method')->map(fn ($m): string => (string) $m)->all(),
            ...DB::table('report_daily_payments')->distinct()->orderBy('payment_method')->pluck('payment_method')->map(fn ($m): string => (string) $m)->all(),
        ]));
    }

    private function sales(ReportFilter $f): Builder
    {
        return $this->scoped(DB::table('report_daily_sales'), $f)->selectRaw(
            'COALESCE(SUM(orders), 0) AS orders, COALESCE(SUM(gross_minor), 0) AS gross, COALESCE(SUM(discounts_minor), 0) AS discounts, COALESCE(SUM(shipping_minor), 0) AS shipping, '
            .'COALESCE(SUM(cod_fee_minor), 0) AS cod_fee, COALESCE(SUM(tax_minor), 0) AS tax, COALESCE(SUM(billed_minor), 0) AS billed, COALESCE(SUM(cancelled_orders), 0) AS cancelled_orders, '
            .'COALESCE(SUM(cancelled_billed_minor), 0) AS cancelled_billed'
        );
    }

    private function payments(ReportFilter $f): Builder
    {
        return $this->scoped(DB::table('report_daily_payments'), $f)->selectRaw(
            'COALESCE(SUM(collected_minor), 0) AS collected, COALESCE(SUM(refunded_minor), 0) AS refunded, COALESCE(SUM(refunded_live_minor), 0) AS refunded_live'
        );
    }

    private function scoped(Builder $q, ReportFilter $f): Builder
    {
        $q->whereBetween('day', [$f->from, $f->to])->where('currency', (string) config('reporting.currency'));   // unique (day, ...)
        foreach (['channel' => $f->channel, 'payment_method' => $f->method, 'locale' => $f->locale] as $column => $value) {
            if ($value !== null && $value !== '') {
                $q->where($column, $value);
            }
        }

        return $q;
    }

    private function combine(?object $s, ?object $p): MetricInputs
    {
        return new MetricInputs(
            (int) ($s->orders ?? 0), (int) ($s->gross ?? 0), (int) ($s->discounts ?? 0), (int) ($s->shipping ?? 0), (int) ($s->cod_fee ?? 0), (int) ($s->tax ?? 0), (int) ($s->billed ?? 0),
            (int) ($s->cancelled_orders ?? 0), (int) ($s->cancelled_billed ?? 0), (int) ($p->collected ?? 0), (int) ($p->refunded ?? 0), (int) ($p->refunded_live ?? 0),
        );
    }
}
