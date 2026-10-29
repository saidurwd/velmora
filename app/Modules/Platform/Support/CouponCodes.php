<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

final class CouponCodes
{
    /** Codes are typed on phones: trim, upper-case, drop spaces, Bangla digits to ASCII. Invalid shapes return ''. */
    public static function normalise(string $typed): string
    {
        $code = mb_strtoupper((string) preg_replace('/\s+/u', '', BanglaText::digitsToAscii(BanglaText::input($typed))));

        return preg_match('/^[A-Z0-9_-]{3,64}$/', $code) === 1 ? $code : '';
    }
}
