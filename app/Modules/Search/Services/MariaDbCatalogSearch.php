<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Search\Contracts\CatalogSearch;
use App\Modules\Search\DTOs\SearchResult;
use App\Modules\Search\DTOs\Suggestion;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Search on MariaDB (ADR-020). Ranking, best first:
 *   exact SKU 1000 → SKU prefix 800 → exact title 900 → title prefix 700 → words (FULLTEXT, title weighted) 300–700 →
 *   loose "contains" match 150–200.
 * Synonyms widen each word; if nothing matches, misspelt words are corrected against the indexed vocabulary.
 */
final class MariaDbCatalogSearch implements CatalogSearch
{
    public function __construct(private readonly SynonymExpander $synonyms, private readonly TypoCorrector $typos, private readonly Clock $clock) {}

    public function search(string $query, string $locale, int $limit = 200): SearchResult
    {
        $tokens = TextNormalizer::tokens($query);
        $normalized = implode(' ', $tokens);

        if ($tokens === []) {
            return new SearchResult('', []);
        }

        $scores = $this->sku($tokens) + [];
        $this->merge($scores, $this->title($normalized, $locale));
        $this->merge($scores, $this->words($tokens, $locale));

        $corrected = null;
        if ($scores === []) {
            $fixed = $this->typos->correct($tokens);
            if ($fixed !== null) {
                $this->merge($scores, $this->words($fixed, $locale));
                $corrected = $scores === [] ? null : implode(' ', $fixed);
            }
        }

        arsort($scores);
        $ids = array_keys($scores);
        usort($ids, fn ($a, $b) => $scores[$b] <=> $scores[$a] ?: $b <=> $a); // equal scores: newer product first, deterministic

        return new SearchResult($normalized, array_slice(array_map('intval', $ids), 0, $limit), $corrected);
    }

    public function suggest(string $query, string $locale, int $limit = 6): array
    {
        $result = $this->search($query, $locale, $limit);
        if ($result->productIds === []) {
            return [];
        }

        $rows = DB::table('search_index')->where('locale', $locale)->whereIn('product_id', $result->productIds)->get(['product_id', 'display_name', 'slug'])->keyBy('product_id');
        $out = [];
        foreach ($result->productIds as $id) {
            if (($r = $rows->get($id)) !== null) {
                $out[] = new Suggestion((string) $r->display_name, (string) $r->slug);
            }
        }

        return $out;
    }

    public function logQuery(string $normalizedQuery, string $locale, int $resultCount): void
    {
        if ($normalizedQuery === '') {
            return;
        }

        DB::table('search_queries')->insert(['query' => mb_substr($normalizedQuery, 0, 120), 'locale' => $locale, 'result_count' => $resultCount, 'created_at' => $this->clock->now()->format('Y-m-d H:i:s.u')]);
    }

    /**
     * @param  list<string>  $tokens
     * @return array<int, int>
     */
    private function sku(array $tokens): array
    {
        $compact = implode('', $tokens);
        if (mb_strlen($compact) < 3) {
            return [];
        }

        $out = [];
        foreach (DB::table('search_skus')->where('sku_norm', 'like', $this->like($compact).'%')->limit(50)->get(['product_id', 'sku_norm']) as $r) {
            $out[(int) $r->product_id] = max($out[(int) $r->product_id] ?? 0, (string) $r->sku_norm === $compact ? 1000 : 800);
        }

        return $out;
    }

    /** @return array<int, int> */
    private function title(string $normalized, string $locale): array
    {
        $out = [];
        $rows = DB::table('search_index')->where('locale', $locale)->where(fn (Builder $q) => $q->where('title', $normalized)->orWhere('title', 'like', $this->like($normalized).'%'))->limit(100)->get(['product_id', 'title']);
        foreach ($rows as $r) {
            $out[(int) $r->product_id] = (string) $r->title === $normalized ? 900 : 700;
        }

        return $out;
    }

    /**
     * Every typed word must match (itself or a synonym). Words FULLTEXT can index go through MATCH; short words and
     * stopwords through LIKE. If that finds little, a looser "contains" pass catches compounds (ফোন inside হেডফোন).
     *
     * @param  list<string>  $tokens
     * @return array<int, int>
     */
    private function words(array $tokens, string $locale): array
    {
        $groups = $this->synonyms->groups($tokens);
        $out = $this->wordQuery($groups, $locale, loose: false);

        if (count($out) < 8) {
            $this->merge($out, $this->wordQuery($groups, $locale, loose: true));
        }

        return $out;
    }

    /**
     * @param  list<list<string>>  $groups
     * @return array<int, int>
     */
    private function wordQuery(array $groups, string $locale, bool $loose): array
    {
        $q = DB::table('search_index')->where('locale', $locale);
        $boolean = [];

        foreach ($groups as $alts) {
            $ft = ! $loose ? array_values(array_filter($alts, fn ($a) => TextNormalizer::isFulltextToken($a))) : [];

            if ($ft !== [] && count($ft) === count($alts)) {
                $boolean[] = '+('.implode(' ', array_map(fn ($a) => str_contains($a, ' ') ? '"'.$a.'"' : $a.'*', $alts)).')';

                continue;
            }

            $q->where(function (Builder $w) use ($alts, $loose): void {
                foreach ($alts as $a) {
                    $pattern = $loose ? '%'.$this->like($a).'%' : $this->like($a).'%';
                    $w->orWhere('title', 'like', $pattern)->orWhere('body', 'like', $loose ? $pattern : '% '.$this->like($a).'%')->orWhere('body', 'like', $this->like($a).'%');
                }
            });
        }

        if ($boolean !== []) {
            $expr = implode(' ', $boolean);
            $q->selectRaw('product_id, MATCH(title, body) AGAINST (? IN BOOLEAN MODE) AS rel, MATCH(title) AGAINST (? IN BOOLEAN MODE) AS trel, title', [$expr, $expr])
                ->whereRaw('MATCH(title, body) AGAINST (? IN BOOLEAN MODE)', [$expr]);
        } else {
            $q->select('product_id', DB::raw('0 AS rel'), DB::raw('0 AS trel'), 'title');
        }

        $out = [];
        foreach ($q->limit(500)->get() as $r) {
            $base = $loose ? 150 : ($boolean === [] ? 200 : 300);
            $out[(int) $r->product_id] = $base + (int) min(10, (float) $r->rel) * 10 + (int) min(10, (float) $r->trel) * 30 + ($this->titleHas((string) $r->title, $groups) ? 50 : 0);
        }

        return $out;
    }

    /** @param  list<list<string>>  $groups */
    private function titleHas(string $title, array $groups): bool
    {
        foreach ($groups as $alts) {
            if (! array_filter($alts, fn ($a) => str_contains($title, $a))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, int>  $into
     * @param  array<int, int>  $more
     */
    private function merge(array &$into, array $more): void
    {
        foreach ($more as $id => $score) {
            $into[$id] = max($into[$id] ?? 0, $score);
        }
    }

    private function like(string $s): string
    {
        return addcslashes($s, '%_\\');
    }
}
