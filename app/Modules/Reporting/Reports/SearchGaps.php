<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Services\ReportDays;
use App\Modules\Search\Contracts\SearchFigures;

/**
 * What customers searched for and did not find, so the shop can add the product or a synonym. Customers type anything into a search box, so: only words searched at least twice are
 * listed, words that look like personal data (an email address, or seven or more digits) are added together in one hidden row, and everything searched once is one row at the end.
 */
final class SearchGaps implements ReportDefinition
{
    public const LIMIT = 200;

    public function __construct(private readonly SearchFigures $figures, private readonly ReportDays $days) {}

    public function code(): string
    {
        return 'search_gaps';
    }

    public function permissions(): array
    {
        return ['reports.view'];
    }

    public function filters(): array
    {
        return ['period'];
    }

    public function columns(): array
    {
        return [new ReportColumn('query'), new ReportColumn('language'), new ReportColumn('times', 'count'), new ReportColumn('last_searched', 'date')];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $from = $this->days->bounds($filter->from)[0];
        $to = $this->days->bounds($filter->to)[1];
        $shown = 0;
        $hidden = 0;
        $hiddenLast = null;

        foreach ($this->figures->repeatedNoResults($from, $to, self::LIMIT) as $gap) {
            $shown += $gap->times;
            if ($this->looksPersonal($gap->query)) {
                $hidden += $gap->times;
                $hiddenLast = $hiddenLast === null || $gap->lastAt > $hiddenLast ? $gap->lastAt : $hiddenLast;

                continue;
            }
            yield ['query' => $gap->query, 'language' => $this->language($gap->locale), 'times' => $gap->times, 'last_searched' => $gap->lastAt->setTimezone($this->days->zone())->format('Y-m-d')];
        }
        if ($hidden > 0) {
            yield ['query' => __('reporting::catalogue.search_gaps.hidden'), 'language' => null, 'times' => $hidden, 'last_searched' => $hiddenLast?->setTimezone($this->days->zone())->format('Y-m-d')];
        }
        $other = $this->figures->noResultTotal($from, $to) - $shown;
        if ($other > 0) {
            yield ['query' => __('reporting::catalogue.search_gaps.other'), 'language' => null, 'times' => $other, 'last_searched' => null];
        }
    }

    /** An email address, or seven or more digits (in any script) anywhere in the words. */
    private function looksPersonal(string $query): bool
    {
        return str_contains($query, '@') || preg_match_all('/\p{Nd}/u', $query) >= 7;
    }

    private function language(string $locale): string
    {
        $key = 'reporting::catalogue.language.'.$locale;

        return app('translator')->has($key) ? __($key) : $locale;
    }
}
