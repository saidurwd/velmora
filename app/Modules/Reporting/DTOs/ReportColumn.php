<?php

declare(strict_types=1);

namespace App\Modules\Reporting\DTOs;

/**
 * One column of a report. `kind` says how a value is shown and written to a file: 'text', 'date' (Y-m-d), 'count', 'money' (minor units; a file gets taka with two decimals),
 * 'percent' (basis points; a file gets the percentage with two decimals). A `finance` column is only for people who may see finance figures; a `pii` column holds personal data and
 * is masked unless the person may see it.
 */
final readonly class ReportColumn
{
    public function __construct(public string $key, public string $kind = 'text', public bool $finance = false, public bool $pii = false) {}
}
