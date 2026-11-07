<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;

/**
 * One report, defined once: the screen and the CSV export both read it, so they cannot disagree. Words are in `reporting::catalogue.<code>.title|description|columns.<key>`.
 */
interface ReportDefinition
{
    /** A stable name for links and files (lower-case letters and underscores). */
    public function code(): string;

    /** @return list<string> every permission needed to open the report (finance columns also need `reports.finance.view`, personal data `customers.view_pii`) */
    public function permissions(): array;

    /** @return list<'period'|'channel'|'method'|'locale'> the choices that mean something for this report */
    public function filters(): array;

    /** @return list<ReportColumn> */
    public function columns(): array;

    /**
     * The rows, one array per row keyed by column key, produced one at a time so a big report never sits in memory. Values: text/date as strings, counts/money/percent as integers.
     *
     * @return iterable<array<string, string|int|null>>
     */
    public function rows(ReportFilter $filter): iterable;
}
