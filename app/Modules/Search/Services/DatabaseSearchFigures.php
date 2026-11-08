<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Modules\Search\Contracts\SearchFigures;
use App\Modules\Search\DTOs\SearchGap;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseSearchFigures implements SearchFigures
{
    public function repeatedNoResults(CarbonImmutable $from, CarbonImmutable $to, int $limit): array
    {
        // Index search_queries (result_count, created_at): the period's no-result searches, then a group by.
        $rows = DB::table('search_queries')->where('result_count', 0)->where('created_at', '>=', $this->at($from))->where('created_at', '<', $this->at($to))
            ->groupBy('query', 'locale')->havingRaw('COUNT(*) >= 2')->orderByRaw('COUNT(*) DESC')->orderBy('query')->orderBy('locale')->limit(max(1, $limit))
            ->selectRaw('query, locale, COUNT(*) AS times, MAX(created_at) AS last_at')->get();

        return array_values($rows->map(fn ($r): SearchGap => new SearchGap((string) $r->query, (string) $r->locale, (int) $r->times, CarbonImmutable::parse((string) $r->last_at, 'UTC')))->all());
    }

    public function noResultTotal(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return DB::table('search_queries')->where('result_count', 0)->where('created_at', '>=', $this->at($from))->where('created_at', '<', $this->at($to))->count();   // index (result_count, created_at)
    }

    private function at(CarbonImmutable $t): string
    {
        return $t->utc()->format('Y-m-d H:i:s.u');
    }
}
