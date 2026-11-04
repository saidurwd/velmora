<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use App\Modules\Platform\Support\BanglaText;

/** A percentage typed by a person ("50", "12.5") and the whole number of basis points the shop stores (5000, 1250). No floating point is used. */
final class Percent
{
    /** @return int|null null when the text is not a percentage from 0 to 100 with at most two decimals */
    public static function toBasisPoints(string $text): ?int
    {
        $text = str_replace(',', '.', trim(BanglaText::digitsToAscii($text)));
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $m) !== 1) {
            return null;
        }
        $bp = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');

        return $bp <= 10000 ? $bp : null;
    }

    public static function fromBasisPoints(int $bp): string
    {
        $text = sprintf('%d.%02d', intdiv($bp, 100), $bp % 100);

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
