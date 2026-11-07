<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Services\ReportReader;

/**
 * The tax BILLED on orders placed in the period, per kind of charge (goods, delivery, cash-on-delivery fee) and rate, with a total row. It is what the orders say, not a tax return:
 * cancelled orders are left out, nothing is taken off for refunds (the tax part of a refund is not recorded), and there are no credit notes. The accountant must confirm how it is used.
 */
final class TaxSummary implements ReportDefinition
{
    public function __construct(private readonly ReportReader $reader) {}

    public function code(): string
    {
        return 'tax_summary';
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
        return [
            new ReportColumn('charge'), new ReportColumn('rate', 'percent'), new ReportColumn('prices'), new ReportColumn('lines', 'count'), new ReportColumn('taxable', 'money'), new ReportColumn('tax', 'money'),
        ];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $lines = 0;
        $taxable = 0;
        $tax = 0;
        foreach ($this->reader->taxRows($filter) as $r) {
            $lines += $r['lines'];
            $taxable += $r['taxable'];
            $tax += $r['tax'];
            yield [
                'charge' => __('reporting::catalogue.common.charge_'.$r['kind']), 'rate' => $r['rate_bp'], 'prices' => __('reporting::catalogue.common.'.($r['tax_inclusive'] ? 'tax_included' : 'tax_excluded')),
                'lines' => $r['lines'], 'taxable' => $r['taxable'], 'tax' => $r['tax'],
            ];
        }
        yield ['charge' => __('reporting::catalogue.common.total'), 'rate' => null, 'prices' => '', 'lines' => $lines, 'taxable' => $taxable, 'tax' => $tax];
    }
}
