<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Integrity;

use App\Modules\Platform\Contracts\IntegrityCheck;
use App\Modules\Platform\DTOs\IntegrityResult;
use Illuminate\Support\Facades\DB;

/** The number of times a coupon says it was used equals the redemptions recorded for it. */
final class CouponUseCountsCheck implements IntegrityCheck
{
    public function code(): string
    {
        return 'coupon_use_counts';
    }

    public function run(): IntegrityResult
    {
        $cap = (int) config('ops.integrity.max_problems');

        $rows = DB::select("SELECT c.public_id AS id, c.used_count AS counted, COALESCE(r.n, 0) AS redeemed
              FROM coupons c LEFT JOIN (SELECT coupon_id, COUNT(*) AS n FROM coupon_redemptions GROUP BY coupon_id) r ON r.coupon_id = c.id
             WHERE c.used_count <> COALESCE(r.n, 0) LIMIT {$cap}");

        return new IntegrityResult(count($rows), array_values(array_map(fn ($r) => "coupon {$r->id}: says {$r->counted} uses, {$r->redeemed} redemptions", array_slice($rows, 0, (int) config('ops.integrity.sample')))));
    }
}
