<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Orders\Contracts\OrderExportRows;
use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Services\ReportDays;

/** One row per customer who ordered in the period, for staff who may export customers: masked name, masked phone and order count unless the person may see personal data. */
final class CustomersFile implements ReportDefinition
{
    public function __construct(private readonly OrderExportRows $rows, private readonly ReportDays $days) {}

    public function code(): string
    {
        return 'customers_file';
    }

    public function permissions(): array
    {
        return ['reports.view', 'customers.export'];
    }

    public function filters(): array
    {
        return ['period'];
    }

    public function columns(): array
    {
        return [
            new ReportColumn('name', pii: true), new ReportColumn('phone', pii: true), new ReportColumn('orders', 'count'), new ReportColumn('first_order'), new ReportColumn('last_order'), new ReportColumn('total', 'money', finance: true),
        ];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $from = $this->days->bounds($filter->from)[0];
        $to = $this->days->bounds($filter->to)[1];

        yield from $this->rows->customers($from, $to);
    }
}
