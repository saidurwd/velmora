<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Pricing\Contracts\CouponRedeemer;
use App\Modules\Pricing\DTOs\AppliedDiscount;
use App\Modules\Pricing\DTOs\DiscountOutcome;
use App\Modules\Pricing\Exceptions\CouponException;
use Illuminate\Support\Facades\DB;

/**
 * Atomic redemption. One conditional UPDATE both checks and takes a use ("used_count < usage_limit" is part of the WHERE), so ten
 * sessions racing for the last use cannot all win; the row lock it takes also serialises the per-customer count that follows.
 * Retrying the same order is harmless (UNIQUE coupon + order). Run inside the order's transaction so any failure undoes it all.
 */
final class RedeemCoupons implements CouponRedeemer
{
    public function __construct(private readonly Clock $clock) {}

    public function redeem(DiscountOutcome $outcome, int $orderId, ?string $redeemer): void
    {
        $coupons = array_values(array_filter($outcome->applied, fn (AppliedDiscount $a) => $a->kind === 'coupon' && $a->code !== null));
        usort($coupons, fn (AppliedDiscount $a, AppliedDiscount $b) => strcmp((string) $a->code, (string) $b->code)); // same order everywhere: no deadlocks between two orders sharing coupons

        Transactions::run(function () use ($coupons, $orderId, $redeemer): void {
            foreach ($coupons as $applied) {
                $this->redeemOne((string) $applied->code, $applied->amountMinor, $orderId, $redeemer);
            }
        });
    }

    private function redeemOne(string $code, int $amountMinor, int $orderId, ?string $redeemer): void
    {
        $now = $this->clock->now()->utc()->format('Y-m-d H:i:s.u');
        $coupon = DB::table('coupons')->where('code', $code)->first(['id']) ?? throw new CouponException('unknown', $code);

        if (DB::table('coupon_redemptions')->where('coupon_id', $coupon->id)->where('order_id', $orderId)->exists()) {
            return; // this order already used this coupon (a retry)
        }

        $taken = DB::update(
            'UPDATE coupons SET used_count = used_count + 1, updated_at = ? WHERE id = ? AND is_active = 1 AND (usage_limit IS NULL OR used_count < usage_limit) AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at > ?)',
            [$now, $coupon->id, $now, $now],
        );

        if ($taken !== 1) {
            $row = DB::table('coupons')->where('id', $coupon->id)->first() ?? throw new CouponException('unknown', $code);
            throw new CouponException(match (true) {
                ! $row->is_active => 'inactive',
                $row->ends_at !== null && (string) $row->ends_at <= $now => 'expired',
                $row->starts_at !== null && (string) $row->starts_at > $now => 'not_started',
                default => 'exhausted',
            }, $code);
        }

        $limit = DB::table('coupons')->where('id', $coupon->id)->value('per_customer_limit');
        if ($limit !== null && $redeemer !== null && DB::table('coupon_redemptions')->where('coupon_id', $coupon->id)->where('redeemer', $redeemer)->count() >= (int) $limit) {
            throw new CouponException('per_customer_limit', $code); // rolls back the use taken above
        }

        DB::table('coupon_redemptions')->insert(['coupon_id' => $coupon->id, 'order_id' => $orderId, 'redeemer' => $redeemer, 'amount_minor' => $amountMinor, 'redeemed_at' => $now]);
    }
}
