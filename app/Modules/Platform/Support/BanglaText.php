<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Normalizer;

/**
 * Text normalisation for Bangla/English input (BD-35, BD-37).
 * Stored text is NFC with ASCII digits; Bangla digits are a display option only.
 */
final class BanglaText
{
    private const DIGITS = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];

    /** Zero-width joiner / non-joiner / space / BOM / soft hyphen. */
    private const INVISIBLE = "/[\u{200B}-\u{200D}\u{2060}\u{FEFF}\u{00AD}]/u";

    /** NFC + control-character strip + trim. Use for any free-text input. */
    public static function input(string $text): string
    {
        $text = self::nfc($text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return trim($text);
    }

    /** Bangla digits (০–৯) to ASCII. For phone, quantity, price, OTP, postal-code fields. */
    public static function digitsToAscii(string $text): string
    {
        return strtr($text, self::DIGITS);
    }

    /** ASCII digits to Bangla digits. Display only. */
    public static function digitsToBangla(string $text): string
    {
        return strtr($text, array_flip(self::DIGITS));
    }

    /** Numeric-field normaliser: NFC, ASCII digits, no spaces/invisibles. */
    public static function numeric(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', self::digitsToAscii(self::stripInvisible(self::nfc($text))));
    }

    /** Search normaliser: NFC, ASCII digits, invisibles removed, lowercase, collapsed whitespace. */
    public static function forSearch(string $text): string
    {
        $text = self::digitsToAscii(self::stripInvisible(self::nfc($text)));
        $text = mb_strtolower($text, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function nfc(string $text): string
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

        return $normalized === false ? $text : $normalized;
    }

    public static function stripInvisible(string $text): string
    {
        return (string) preg_replace(self::INVISIBLE, '', $text);
    }
}
