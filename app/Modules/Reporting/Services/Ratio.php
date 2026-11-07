<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

/** The one way a share is worked out: part of a whole, in basis points (10000 = 100%), rounded to the nearest, with no division by zero. */
final class Ratio
{
    public static function basisPoints(int $part, int $whole): int
    {
        return $whole <= 0 ? 0 : intdiv(2 * $part * 10000 + $whole, 2 * $whole);
    }
}
