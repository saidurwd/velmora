<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Pricing\Contracts\CouponFigures;
use App\Modules\Pricing\DTOs\CouponUse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseCouponFigures implements CouponFigures
{
    public function usedBetween(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        // Index coupon_redemptions (redeemed_at, coupon_id): the period's uses, read in pages by id.
        $query = DB::table('coupon_redemptions as r')->join('coupons as c', 'c.id', '=', 'r.coupon_id')
            ->where('r.redeemed_at', '>=', $from->utc()->format('Y-m-d H:i:s.u'))->where('r.redeemed_at', '<', $to->utc()->format('Y-m-d H:i:s.u'))
            ->select('r.id', 'c.code', 'r.order_id', 'r.amount_minor');

        foreach ($query->lazyById(1000, 'r.id', 'id') as $row) {
            yield new CouponUse((string) $row->code, (int) $row->order_id, (int) $row->amount_minor);
        }
    }
}
