<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Payments\Contracts\CodAgingFigures;
use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;

/** Where the cash-on-delivery money is stuck, as of now: cash not yet recorded, and cash recorded but not yet covered by a courier statement, by how many days old. */
final class CodAging implements ReportDefinition
{
    public function __construct(private readonly CodAgingFigures $aging) {}

    public function code(): string
    {
        return 'cod_aging';
    }

    public function permissions(): array
    {
        return ['reports.view', 'reports.finance.view'];
    }

    public function filters(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [new ReportColumn('kind'), new ReportColumn('age_days'), new ReportColumn('orders', 'count'), new ReportColumn('amount', 'money')];
    }

    public function rows(ReportFilter $filter): iterable
    {
        foreach ($this->aging->lines() as $line) {
            yield ['kind' => $line->kind, 'age_days' => $line->bucket, 'orders' => $line->count, 'amount' => $line->amountMinor];
        }
    }
}
