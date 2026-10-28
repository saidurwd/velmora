<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use Illuminate\Support\Facades\DB;

/** SKU rules (owner-approved): 3–64 characters, letters/digits/dashes, case-insensitive unique. Suggestions are editable. */
final class SkuGenerator
{
    public const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9-]{1,62}[A-Za-z0-9]$/';

    public static function isValid(string $sku): bool
    {
        return preg_match(self::PATTERN, $sku) === 1;
    }

    /** @param  list<string>  $parts  e.g. option value codes */
    public function suggest(string $base, array $parts = []): string
    {
        $raw = strtoupper(implode('-', array_merge([$base], $parts)));
        $clean = trim((string) preg_replace('/[^A-Z0-9]+/', '-', $raw), '-');
        $clean = substr($clean === '' ? 'SKU' : $clean, 0, 58);
        $clean = str_pad($clean, 3, 'X');

        $candidate = $clean;
        for ($i = 2; DB::table('product_variants')->where('sku', $candidate)->exists(); $i++) {
            $candidate = $clean.'-'.$i;
        }

        return $candidate;
    }
}
