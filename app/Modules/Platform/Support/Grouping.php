<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/** Digit grouping: international (1,250,000) for English, lakh/crore (12,50,000) for Bangla (owner decision). */
final class Grouping
{
    public static function western(string $digits): string
    {
        return strrev(implode(',', str_split(strrev($digits), 3)));
    }

    public static function lakhCrore(string $digits): string
    {
        if (strlen($digits) <= 3) {
            return $digits;
        }

        return strrev(implode(',', str_split(strrev(substr($digits, 0, -3)), 2))).','.substr($digits, -3);
    }

    public static function forLocale(string $digits, string $locale): string
    {
        return $locale === 'bn' ? self::lakhCrore($digits) : self::western($digits);
    }
}
