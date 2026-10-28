<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use Illuminate\Support\Facades\DB;

/** Admin-managed synonym pairs, applied in both directions (so মোবাইল ⇄ mobile, and a chain is NOT followed: pairs are explicit). */
final class SynonymExpander
{
    /**
     * @param  list<string>  $tokens
     * @return list<list<string>> for every token, the token itself followed by its synonyms
     */
    public function groups(array $tokens): array
    {
        if ($tokens === []) {
            return [];
        }

        $forward = DB::table('search_synonyms')->whereIn('term', $tokens)->get(['term as a', 'synonym as b']);
        $backward = DB::table('search_synonyms')->whereIn('synonym', $tokens)->get(['synonym as a', 'term as b']);
        $map = [];
        foreach ($forward->concat($backward) as $r) {
            $map[(string) $r->a][] = (string) $r->b;
        }

        return array_map(fn (string $t) => array_values(array_unique([$t, ...($map[$t] ?? [])])), $tokens);
    }
}
