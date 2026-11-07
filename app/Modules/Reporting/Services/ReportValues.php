<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Reports\ReportDefinition;
use Illuminate\Support\Facades\Lang;

/** What a person (or a file) gets of a report: only the columns they may see, personal data masked unless they may see it, and values written the way a spreadsheet wants them. */
final class ReportValues
{
    /** @return list<ReportColumn> */
    public function visibleColumns(ReportDefinition $report, bool $finance): array
    {
        return array_values(array_filter($report->columns(), fn (ReportColumn $c): bool => $finance || ! $c->finance));
    }

    public function label(ReportDefinition $report, string $key): string
    {
        foreach (["reporting::catalogue.{$report->code()}.columns.{$key}", "reporting::metrics.{$key}.label", "reporting::catalogue.common.{$key}"] as $path) {
            if (Lang::has($path)) {
                return (string) __($path);
            }
        }

        return $key;
    }

    /** @return list<string> the choices that are used by this report, with the ones it does not support dropped */
    public function usedFilters(ReportDefinition $report, ReportFilter $filter): array
    {
        return array_values(array_filter(['channel', 'method', 'locale'], fn (string $f): bool => in_array($f, $report->filters(), true) && $filter->{$f} !== null));
    }

    /** The filter the report will really use: choices it does not have are dropped, and a report without a period gets today's. */
    public function normalise(ReportDefinition $report, ReportFilter $filter, string $today): ReportFilter
    {
        $has = fn (string $f): bool => in_array($f, $report->filters(), true);

        return new ReportFilter(
            $has('period') ? $filter->from : $today,
            $has('period') ? $filter->to : $today,
            $has('channel') ? $filter->channel : null,
            $has('method') ? $filter->method : null,
            $has('locale') ? $filter->locale : null,
        );
    }

    /** A value as it is written into a file: money in taka with two decimals, percent with two decimals, personal data masked unless allowed. */
    public function forFile(ReportColumn $column, string|int|null $value, bool $pii): string
    {
        if ($value === null) {
            return '';
        }
        if ($column->pii && ! $pii) {
            return $this->mask((string) $value);
        }

        return match ($column->kind) {
            'money', 'percent' => $this->twoDecimals((int) $value),
            'count' => (string) (int) $value,
            default => (string) $value,
        };
    }

    public function isNumeric(ReportColumn $column): bool
    {
        return in_array($column->kind, ['money', 'percent', 'count'], true);
    }

    public function mask(string $value): string
    {
        if (str_contains($value, '@')) {
            [$name, $domain] = explode('@', $value, 2);

            return mb_substr($name, 0, 1).'***@'.$domain;
        }
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) >= 6) {
            return substr($value, 0, 4).'****'.substr($digits, -3);
        }

        return mb_substr($value, 0, 1).'***';
    }

    private function twoDecimals(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }
}
