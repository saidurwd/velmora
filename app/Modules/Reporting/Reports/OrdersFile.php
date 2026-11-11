<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Orders\Contracts\OrderExportRows;
use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Services\ReportDays;

/** One row per order placed in the period, for staff who may export orders. Name and phone are personal data: masked unless the person may see it. */
final class OrdersFile implements ReportDefinition
{
    public function __construct(private readonly OrderExportRows $rows, private readonly ReportDays $days) {}

    public function code(): string
    {
        return 'orders_file';
    }

    public function permissions(): array
    {
        return ['reports.view', 'orders.export'];
    }

    public function filters(): array
    {
        return ['period', 'channel', 'method', 'locale'];
    }

    public function columns(): array
    {
        return [
            new ReportColumn('number'), new ReportColumn('placed'), new ReportColumn('status'), new ReportColumn('payment_status'), new ReportColumn('fulfillment'), new ReportColumn('channel'), new ReportColumn('payment_method'),
            new ReportColumn('language'), new ReportColumn('items', 'count'), new ReportColumn('currency'), new ReportColumn('total', 'money', finance: true), new ReportColumn('name', pii: true), new ReportColumn('phone', pii: true),
        ];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $from = $this->days->bounds($filter->from)[0];
        $to = $this->days->bounds($filter->to)[1];

        yield from $this->rows->orders($from, $to, $filter->channel, $filter->method, $filter->locale);
    }
}
