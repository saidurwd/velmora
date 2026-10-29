<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\CouponCodes;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Pricing\DTOs\CouponData;
use App\Modules\Pricing\Exceptions\PromotionException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates or edits a coupon. The code is fixed once made (carts and receipts refer to it). The usage count belongs to the
 * redemption step alone: nothing here can change it, and the limit cannot be set below what has been used.
 */
final class SaveCoupon
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws PromotionException */
    public function handle(Actor $actor, ?int $couponId, ?string $typedCode, CouponData $d): int
    {
        $this->check($d);

        $id = null;
        try {
            $id = Transactions::run(function () use ($couponId, $typedCode, $d): int {
                $now = now('UTC');
                $row = [
                    'description' => $d->description === null ? null : (mb_substr(trim(BanglaText::input($d->description)), 0, 255) ?: null),
                    'type' => $d->type, 'value' => $d->type === 'free_shipping' ? 0 : $d->value,
                    'max_discount_minor' => $d->type === 'percent' ? $d->maxDiscountMinor : null, 'min_spend_minor' => $d->minSpendMinor, 'scope' => $d->scope,
                    'starts_at' => $d->startsAt?->utc()->format('Y-m-d H:i:s.u'), 'ends_at' => $d->endsAt?->utc()->format('Y-m-d H:i:s.u'),
                    'usage_limit' => $d->usageLimit, 'per_customer_limit' => $d->perCustomerLimit, 'is_active' => $d->isActive, 'updated_at' => $now,
                ];

                if ($couponId === null) {
                    $code = CouponCodes::normalise((string) $typedCode);
                    if ($code === '') {
                        throw new PromotionException('coupon_code_invalid');
                    }
                    $couponId = (int) DB::table('coupons')->insertGetId($row + ['public_id' => strtolower((string) Str::ulid()), 'code' => $code, 'used_count' => 0, 'created_at' => $now]);
                } else {
                    $used = (int) DB::table('coupons')->where('id', $couponId)->lockForUpdate()->value('used_count');
                    if ($d->usageLimit !== null && $d->usageLimit < $used) {
                        throw new PromotionException('coupon_limit_below_used', ['used' => $used]);
                    }
                    DB::table('coupons')->where('id', $couponId)->update($row);
                }

                DB::table('coupon_products')->where('coupon_id', $couponId)->delete();
                DB::table('coupon_categories')->where('coupon_id', $couponId)->delete();
                if ($d->scope === 'products') {
                    DB::table('coupon_products')->insert(array_map(fn (int $p) => ['coupon_id' => $couponId, 'product_id' => $p], array_values(array_unique($d->productIds))));
                }
                if ($d->scope === 'categories') {
                    DB::table('coupon_categories')->insert(array_map(fn (int $c) => ['coupon_id' => $couponId, 'category_id' => $c], array_values(array_unique($d->categoryIds))));
                }

                return $couponId;
            });
        } catch (UniqueConstraintViolationException) {
            throw new PromotionException('coupon_code_taken');
        }

        $this->audit->record($couponId === null ? 'coupon.created' : 'coupon.saved', 'coupon', [], ['type' => $d->type, 'value' => $d->value, 'scope' => $d->scope, 'usage_limit' => $d->usageLimit, 'is_active' => $d->isActive], $actor, $id);

        return $id;
    }

    private function check(CouponData $d): void
    {
        if ($d->type === 'percent' && ($d->value < 1 || $d->value > 10000)) {
            throw new PromotionException('coupon_percent_range');
        }
        if ($d->type === 'fixed' && $d->value < 1) {
            throw new PromotionException('coupon_amount_required');
        }
        if ($d->scope === 'products' && $d->productIds === []) {
            throw new PromotionException('coupon_scope_empty');
        }
        if ($d->scope === 'categories' && $d->categoryIds === []) {
            throw new PromotionException('coupon_scope_empty');
        }
        if ($d->startsAt !== null && $d->endsAt !== null && ! $d->endsAt->greaterThan($d->startsAt)) {
            throw new PromotionException('window_invalid');
        }
        if (($d->usageLimit !== null && $d->usageLimit < 1) || ($d->perCustomerLimit !== null && $d->perCustomerLimit < 1) || $d->minSpendMinor < 0 || ($d->maxDiscountMinor !== null && $d->maxDiscountMinor < 1)) {
            throw new PromotionException('coupon_numbers_invalid');
        }
    }
}
