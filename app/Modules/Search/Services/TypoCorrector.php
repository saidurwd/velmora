<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use Illuminate\Support\Facades\DB;

/** "Did you mean": swaps unknown words for the closest indexed word (edit distance 1 for short words, 2 for longer). */
final class TypoCorrector
{
    private const CANDIDATES = 3000;

    /**
     * @param  list<string>  $tokens
     * @return list<string>|null corrected tokens, or null when nothing could be corrected
     */
    public function correct(array $tokens): ?array
    {
        $changed = false;
        $out = [];

        foreach ($tokens as $token) {
            $fixed = $this->closest($token);
            $changed = $changed || $fixed !== $token;
            $out[] = $fixed;
        }

        return $changed ? $out : null;
    }

    private function closest(string $token): string
    {
        $length = mb_strlen($token);
        if ($length < 4 || DB::table('search_terms')->where('term', $token)->exists()) {
            return $token;
        }

        $max = $length <= 5 ? 1 : 2;
        $first = mb_substr($token, 0, 1);
        $best = [$max + 1, $token];

        $candidates = DB::table('search_terms')->whereBetween('length', [$length - $max, $length + $max])->where('term', 'like', $this->escape($first).'%')->limit(self::CANDIDATES)->pluck('term');
        foreach ($candidates as $candidate) {
            $d = self::distance($token, (string) $candidate);
            if ($d < $best[0] || ($d === $best[0] && mb_strlen((string) $candidate) < mb_strlen($best[1]))) {
                $best = [$d, (string) $candidate];
            }
        }

        return $best[0] <= $max ? $best[1] : $token;
    }

    /** Levenshtein over characters (PHP's built-in works on bytes and would mangle Bangla). */
    public static function distance(string $a, string $b): int
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $prev = range(0, count($y));

        foreach ($x as $i => $cx) {
            $cur = [$i + 1];
            foreach ($y as $j => $cy) {
                $cur[] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($cx === $cy ? 0 : 1));
            }
            $prev = $cur;
        }

        return $prev[count($y)];
    }

    private function escape(string $s): string
    {
        return addcslashes($s, '%_\\');
    }
}
