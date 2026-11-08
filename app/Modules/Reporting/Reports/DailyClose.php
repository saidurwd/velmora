<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Payments\Contracts\PaymentMethodCatalog;
use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Metrics\MetricCatalogue;
use App\Modules\Reporting\Services\ReportReader;

/**
 * The close sheet: for a day (or a period), what was sold, what was taken off, what was cancelled, the money received by payment method (cash on delivery is its own method, so the
 * cash recorded is its row), the money given back, and the net. Every figure comes from the same summary tables as the sales summary, so the two always agree.
 */
final class DailyClose implements ReportDefinition
{
    public function __construct(private readonly ReportReader $reader, private readonly MetricCatalogue $metrics, private readonly PaymentMethodCatalog $methods) {}

    public function code(): string
    {
        return 'daily_close';
    }

    public function permissions(): array
    {
        return ['reports.view', 'reports.finance.view'];
    }

    public function filters(): array
    {
        return ['period'];
    }

    public function columns(): array
    {
        return [new ReportColumn('section'), new ReportColumn('line'), new ReportColumn('count', 'count'), new ReportColumn('amount', 'money')];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $m = $this->metrics->compute($this->reader->inputs($filter), true);
        $line = fn (string $section, string $line, ?int $count, int $amount): array => [
            'section' => __('reporting::catalogue.daily_close.sections.'.$section), 'line' => __('reporting::catalogue.daily_close.lines.'.$line), 'count' => $count, 'amount' => $amount,
        ];

        yield $line('sales', 'orders', $m['orders'], $m['billed_total']);
        yield $line('sales', 'gross_sales', null, $m['gross_sales']);
        yield $line('sales', 'discounts', null, $m['discounts']);
        yield $line('sales', 'delivery_charges', null, $m['delivery_charges']);
        yield $line('sales', 'cod_fees', null, $m['cod_fees']);
        yield $line('sales', 'tax', null, $m['tax']);
        yield $line('sales', 'cancelled', $m['cancelled_orders'], $m['cancelled_value']);

        foreach ($this->reader->breakdown($filter, 'payment_method') as $code => $inputs) {
            $byMethod = $this->metrics->compute($inputs, true);
            if ($byMethod['collected'] !== 0) {
                yield [
                    'section' => __('reporting::catalogue.daily_close.sections.money'), 'line' => $this->methods->label($code, app()->getLocale()), 'count' => null, 'amount' => $byMethod['collected'],
                ];
            }
        }
        yield $line('money', 'collected', null, $m['collected']);
        yield $line('money', 'refunded', null, $m['refunded']);
        yield $line('result', 'net_sales', null, $m['net_sales']);
    }
}
