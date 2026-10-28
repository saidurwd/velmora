<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Modules\Platform\Support\BanglaText;

/**
 * One normalisation for indexed text AND typed queries, so they always meet in the middle: NFC, Bangla digits → ASCII,
 * zero-width / soft-hyphen characters removed, lower-case, punctuation → space (letters, digits and Bangla vowel signs kept).
 */
final class TextNormalizer
{
    public const MAX_TOKEN = 40;

    public const MAX_QUERY_TOKENS = 8;

    /** Words InnoDB FULLTEXT silently ignores (default stopword list); they must be matched another way. */
    private const STOPWORDS = ['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];

    public static function text(string $text): string
    {
        $text = BanglaText::forSearch(\Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text); // KC also folds full-width and ligature forms
        $text = (string) preg_replace('/[^\p{L}\p{N}\p{M}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<string> distinct tokens, capped */
    public static function tokens(string $text): array
    {
        $tokens = array_filter(explode(' ', self::text($text)), fn ($t) => $t !== '' && mb_strlen($t) <= self::MAX_TOKEN);

        return array_slice(array_values(array_unique($tokens)), 0, self::MAX_QUERY_TOKENS);
    }

    /** True when FULLTEXT can index and match this token (≥ 3 characters and not a stopword). */
    public static function isFulltextToken(string $token): bool
    {
        return mb_strlen($token) >= 3 && ! in_array($token, self::STOPWORDS, true);
    }

    public static function stripHtml(string $html): string
    {
        return html_entity_decode(strip_tags((string) preg_replace('/<(br|p|li|h[1-6])\b[^>]*>/i', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
