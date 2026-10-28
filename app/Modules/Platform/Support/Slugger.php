<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/**
 * URL slugs that keep Bangla letters (Str::slug would drop them). NFC, zero-width characters removed, everything that is
 * not a letter, combining mark or digit becomes a dash. Combining marks (\p{M}) must stay or Bangla words fall apart.
 */
final class Slugger
{
    public const MAX_LENGTH = 120;

    public static function make(string $text): string
    {
        $text = mb_strtolower(BanglaText::digitsToAscii(BanglaText::stripInvisible(BanglaText::nfc($text))), 'UTF-8');
        $slug = trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $text), '-');

        if (mb_strlen($slug) > self::MAX_LENGTH) {
            $slug = rtrim(mb_substr($slug, 0, self::MAX_LENGTH), '-');
        }

        return $slug;
    }
}
