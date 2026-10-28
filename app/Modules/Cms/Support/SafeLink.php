<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

/** Banner links are staff-entered but rendered into public pages: only site-relative paths and https URLs are allowed. */
final class SafeLink
{
    public static function isSafe(string $url): bool
    {
        if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//'); // protocol-relative URLs are external
        }

        return str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
