<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * VM-YYMM-NNNNNN. The six digits are one running counter from a database sequence (never reset, so a number is never reused);
 * YYMM is the month in Dhaka, which is what the shop and its customers mean by "this month".
 */
final class OrderNumbers
{
    public const PREFIX = 'VM';

    public function next(CarbonInterface $placedAt): string
    {
        $value = (int) ((array) DB::selectOne('SELECT NEXTVAL(order_number_seq) AS n'))['n'];

        return self::format($value, $placedAt);
    }

    public static function format(int $sequence, CarbonInterface $at): string
    {
        return sprintf('%s-%s-%06d', self::PREFIX, $at->copy()->setTimezone('Asia/Dhaka')->format('ym'), $sequence);
    }
}
