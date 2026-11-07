<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Payments\Contracts\PaymentMethodCatalog;
use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Metrics\MetricCatalogue;
use App\Modules\Reporting\Services\Ratio;
use App\Modules\Reporting\Services\ReportReader;

/** How customers pay: for each payment method, the orders, what was billed, what share of the billed total that is, and the money received and given back. */
final class PaymentMethods implements ReportDefinition
{
    public function __construct(private readonly ReportReader $reader, private readonly MetricCatalogue $metrics, private readonly PaymentMethodCatalog $methods) {}

    public function code(): string
    {
        return 'payment_methods';
    }

    public function permissions(): array
    {
        return ['reports.view'];
    }

    public function filters(): array
    {
        return ['period', 'channel', 'locale'];
    }

    public function columns(): array
    {
        return [
            new ReportColumn('method'), new ReportColumn('orders', 'count'), new ReportColumn('billed_total', 'money'), new ReportColumn('share', 'percent'),
            new ReportColumn('collected', 'money', finance: true), new ReportColumn('refunded', 'money', finance: true),
        ];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $groups = $this->reader->breakdown(new ReportFilter($filter->from, $filter->to, $filter->channel, null, $filter->locale), 'payment_method');
        $all = array_sum(array_map(fn ($g): int => $g->billedMinor, $groups));
        foreach ($groups as $code => $inputs) {
            $m = $this->metrics->compute($inputs, true);
            yield [
                'method' => $this->methods->label($code, app()->getLocale()), 'orders' => $m['orders'], 'billed_total' => $m['billed_total'],
                'share' => Ratio::basisPoints($inputs->billedMinor, $all), 'collected' => $m['collected'], 'refunded' => $m['refunded'],
            ];
        }
    }
}
