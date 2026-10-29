<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Platform\Support\BanglaText;

/** A stable identity for "the same address typed slightly differently": case, spacing, punctuation and digit script do not matter. */
final class AddressFingerprint
{
    public static function of(string $addressLine, ?string $area, int $upazilaId): string
    {
        $clean = fn (?string $t): string => (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(BanglaText::digitsToAscii(BanglaText::input((string) $t))));

        return hash('sha256', $upazilaId.'|'.$clean($addressLine).'|'.$clean($area));
    }

    public static function phone(string $e164): string
    {
        return hash('sha256', $e164);
    }
}
