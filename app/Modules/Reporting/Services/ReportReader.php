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
        return $this->combine($this->sales($f)->first(), $this->payments($f)->first(), $this->units($f)->first());
    }

    /** @return array<string, MetricInputs> day => sums, for every day in the period that has any sales or money */
    public function perDay(ReportFilter $f): array
    {
        $sales = $this->sales($f)->groupBy('day')->addSelect('day')->get()->keyBy(fn ($r) => (string) $r->day);   // unique (day, ...)
        $money = $this->payments($f)->groupBy('day')->addSelect('day')->get()->keyBy(fn ($r) => (string) $r->day);
        $units = $this->units($f)->groupBy('day')->addSelect('day')->get()->keyBy(fn ($r) => (string) $r->day);
        $out = [];
        foreach ($this->days->range($f->from, $f->to) as $day) {
            if (isset($sales[$day]) || isset($money[$day]) || isset($units[$day])) {
                $out[$day] = $this->combine($sales[$day] ?? null, $money[$day] ?? null, $units[$day] ?? null);
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
        $units = $this->units($f)->groupBy($column)->addSelect($column.' as grp')->get()->keyBy(fn ($r) => (string) $r->grp);
        $names = array_values(array_unique([...$sales->keys()->all(), ...$money->keys()->all(), ...$units->keys()->all()]));
        sort($names);

        $out = [];
        foreach ($names as $name) {
            $out[(string) $name] = $this->combine($sales[$name] ?? null, $money[$name] ?? null, $units[$name] ?? null);
        }

        return $out;
    }

    /**
     * What each product variant sold in the period (units, returned units, goods price, discounts), under the choices of the filter.
     *
     * @return array<int, array{sku: string, inputs: MetricInputs}> keyed by variant id
     */
    public function byVariant(ReportFilter $f): array
    {
        $rows = $this->scoped(DB::table('report_daily_products'), $f)->groupBy('variant_id')->orderBy('variant_id')   // unique (day, variant_id, ...)
            ->selectRaw('variant_id, MAX(sku) AS sku, SUM(units) AS units, SUM(returned_units) AS returned, SUM(gross_minor) AS gross, SUM(discounts_minor) AS discounts')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->variant_id] = ['sku' => (string) $r->sku, 'inputs' => new MetricInputs(grossMinor: (int) $r->gross, discountsMinor: (int) $r->discounts, unitsSold: (int) $r->units, returnedUnits: (int) $r->returned)];
        }

        return $out;
    }

    /**
     * The tax billed in the period per kind of charge and rate (the period only: tax is not split by channel or language).
     *
     * @return list<array{kind: string, rate_bp: int, tax_inclusive: bool, lines: int, taxable: int, tax: int}>
     */
    public function taxRows(ReportFilter $f): array
    {
        $rows = DB::table('report_daily_tax')->whereBetween('day', [$f->from, $f->to])->where('currency', (string) config('reporting.currency'))   // unique (day, ...) range
            ->groupBy('kind', 'rate_bp', 'tax_inclusive')->orderBy('kind')->orderBy('rate_bp')->orderBy('tax_inclusive')
            ->selectRaw('kind, rate_bp, tax_inclusive, SUM(`lines`) AS lines_total, SUM(taxable_minor) AS taxable, SUM(tax_minor) AS tax')->get();

        return array_values($rows->map(fn ($r): array => ['kind' => (string) $r->kind, 'rate_bp' => (int) $r->rate_bp, 'tax_inclusive' => (bool) $r->tax_inclusive, 'lines' => (int) $r->lines_total, 'taxable' => (int) $r->taxable, 'tax' => (int) $r->tax])->all());
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

    private function units(ReportFilter $f): Builder
    {
        return $this->scoped(DB::table('report_daily_products'), $f)->selectRaw('COALESCE(SUM(units), 0) AS units, COALESCE(SUM(returned_units), 0) AS returned_units');
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

    private function combine(?object $s, ?object $p, ?object $u = null): MetricInputs
    {
        return new MetricInputs(
            (int) ($s->orders ?? 0), (int) ($s->gross ?? 0), (int) ($s->discounts ?? 0), (int) ($s->shipping ?? 0), (int) ($s->cod_fee ?? 0), (int) ($s->tax ?? 0), (int) ($s->billed ?? 0),
            (int) ($s->cancelled_orders ?? 0), (int) ($s->cancelled_billed ?? 0), (int) ($p->collected ?? 0), (int) ($p->refunded ?? 0), (int) ($p->refunded_live ?? 0), (int) ($u->units ?? 0), (int) ($u->returned_units ?? 0),
        );
    }
}
