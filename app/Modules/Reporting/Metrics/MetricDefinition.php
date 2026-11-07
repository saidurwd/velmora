<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Metrics;

use App\Modules\Reporting\DTOs\MetricInputs;

/**
 * One number the shop reports, defined once. Dashboards, reports, exports and the API ask the `MetricCatalogue`; none of them has its own formula. The words that
 * explain it to staff are in `reporting::metrics.<code>.label` and `.definition` (English and Bangla).
 */
interface MetricDefinition
{
    /** A stable name, used in files and links. */
    public function code(): string;

    /** 'money' (minor units), 'count' or 'percent' (basis points). */
    public function kind(): string;

    /** True when the number is about money that moved (collected, refunded, net sales): needs `reports.finance.view`. */
    public function finance(): bool;

    public function compute(MetricInputs $in): int;
}
