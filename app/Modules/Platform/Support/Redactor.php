<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/**
 * Removes secrets and PII from arrays/strings before they reach logs or audit rows.
 * Keys are matched by name; free-text values are scrubbed for phones, card-like numbers and URL credentials.
 */
final class Redactor
{
    public const MASK = '[redacted]';

    private const SENSITIVE_KEY = '/pass(word)?|secret|token|otp|authorization|api[_-]?key|store_passwd|signature|cvv|card|cookie|private[_-]?key|credential/i';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
                $out[$key] = self::MASK;
            } elseif (is_array($value)) {
                $out[$key] = self::array($value);
            } elseif (is_string($value)) {
                $out[$key] = self::string($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public static function string(string $text): string
    {
        // credentials in query strings (e.g. SSLCommerz store_passwd=…): key=value pairs with sensitive keys
        $text = (string) preg_replace('/((?:pass(?:word|wd)?|secret|token|api[_-]?key|store_passwd|signature|otp)=)[^&\s"\']+/i', '$1'.self::MASK, $text);

        // user:pass@host
        $text = (string) preg_replace('#(://[^/\s:@]+:)[^@\s/]+@#', '$1'.self::MASK.'@', $text);

        // Bangladeshi phones -> +88017****5678
        $text = (string) preg_replace_callback('/(?<!\d)(?:\+?88)?01[3-9]\d{8}(?!\d)/', function (array $m): string {
            $digits = preg_replace('/\D/', '', $m[0]) ?? '';
            $e164 = '+88'.substr($digits, -11);

            return substr($e164, 0, 6).'****'.substr($e164, -4);
        }, $text);

        // card-like 13–19 digit runs
        return (string) preg_replace('/(?<![\d*])(?:\d[ -]?){13,19}(?![\d*])/', '[card]', $text);
    }
}
