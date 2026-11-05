<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

/**
 * Fills a template's words with values. The only thing a template may contain is `{{ name }}` where `name` is one of its declared variables: no logic, no
 * formats, nothing evaluated. Values are turned into plain text, stripped of control characters and line breaks and cut at 300 characters, and are inserted in one
 * pass, so a value that itself looks like `{{ x }}` is never read again. A variable the message declares as MULTI-LINE (a list of items) keeps its line breaks, up to 2000
 * characters; everything else about it is the same. The result is PLAIN TEXT: the email layout escapes it, the text message sends it as it is.
 */
final class TemplateRenderer
{
    private const PLACEHOLDER = '/\{\{\s*([a-z][a-z0-9_]{0,40})\s*\}\}/';

    private const VALUE_MAX = 300;

    private const MULTILINE_MAX = 2000;

    /** @return list<string> the variable names a text uses, in order, each once */
    public function names(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $m);

        return array_values(array_unique($m[1]));
    }

    /** True when braces are left over after the valid placeholders are taken out (`{{ a.b }}`, `{{ $x }}`, a lone `{{`): those would confuse whoever reads the message. */
    public function hasStrayBraces(string $text): bool
    {
        $rest = (string) preg_replace(self::PLACEHOLDER, '', $text);

        return str_contains($rest, '{{') || str_contains($rest, '}}');
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @param  list<string>  $multiline
     */
    public function render(string $text, array $values, array $multiline = []): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, fn (array $m): string => $this->value($values[$m[1]] ?? null, in_array($m[1], $multiline, true)), $text);
    }

    /** A subject is one line: a line break in it could add headers to the email. */
    public function singleLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function value(mixed $value, bool $multiline = false): string
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        $text = is_scalar($value) ? (string) $value : '';
        if ($multiline) {
            $text = str_replace(["\r\n", "\r"], "\n", $text);
            $text = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $text);   // line breaks stay; every other control character goes

            return mb_substr(trim($text), 0, self::MULTILINE_MAX);
        }
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);

        return mb_substr(trim($text), 0, self::VALUE_MAX);
    }
}
