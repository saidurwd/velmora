<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\CouponCodes;
use App\Modules\Pricing\Contracts\DiscountEvaluator;
use App\Modules\Pricing\DTOs\AppliedDiscount;
use App\Modules\Pricing\DTOs\DiscountLine;
use App\Modules\Pricing\DTOs\DiscountOutcome;
use App\Modules\Pricing\DTOs\DiscountRequest;
use Illuminate\Support\Facades\DB;

/**
 * Rules: at most two coupon codes are looked at, in the order given; only ONE discount coupon (percent / fixed) plus ONE free-shipping
 * coupon can apply together. A coupon's minimum spend and its amount are measured on the goods it applies to (its scope), after sale prices.
 * The coupon amount is spread over those lines to the paisa. The best automatic promotion (if any) is an order-level discount on
 * the whole cart; the pricing engine clamps the total so discounts can never exceed the goods.
 */
final class DatabaseDiscountEvaluator implements DiscountEvaluator
{
    public const MAX_CODES = 2;

    public function __construct(private readonly Clock $clock) {}

    public function evaluate(DiscountRequest $request): DiscountOutcome
    {
        $now = $this->clock->now()->utc()->format('Y-m-d H:i:s.u');
        $applied = [];
        $rejected = [];
        $lineDiscounts = [];
        $freeShipping = false;
        $hasDiscountCoupon = false;

        $codes = [];
        foreach ($request->couponCodes as $typed) {
            $code = CouponCodes::normalise($typed);
            if ($code === '') {
                $rejected[] = ['code' => trim($typed), 'reason' => 'unknown'];
            } elseif (! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        foreach (array_splice($codes, self::MAX_CODES) as $extra) {
            $rejected[] = ['code' => $extra, 'reason' => 'too_many'];
        }

        if ($codes !== []) {
            $coupons = DB::table('coupons')->whereIn('code', $codes)->get()->keyBy('code');
            $used = $request->redeemer === null ? collect() : DB::table('coupon_redemptions')->where('redeemer', $request->redeemer)->whereIn('coupon_id', $coupons->pluck('id'))->groupBy('coupon_id')->pluck(DB::raw('COUNT(*)'), 'coupon_id');
            $scopes = $this->scopes($coupons->all());

            foreach ($codes as $code) {
                $c = $coupons->get($code);
                $reason = $c === null ? 'unknown' : $this->unusable($c, $now, (int) ($used[$c->id] ?? 0), $request->redeemer !== null);
                $eligible = $c === null ? [] : $this->eligible($c, $scopes[$c->id] ?? [], $request->lines);
                $base = array_sum(array_map(fn (DiscountLine $l) => $l->totalMinor, $eligible));

                if ($reason === null && $eligible === []) {
                    $reason = 'not_applicable';
                }
                if ($reason === null && $base < (int) $c->min_spend_minor) {
                    $reason = 'min_spend';
                }
                if ($reason === null && (($c->type === 'free_shipping' && $freeShipping) || ($c->type !== 'free_shipping' && $hasDiscountCoupon))) {
                    $reason = 'not_combinable';
                }

                if ($reason !== null) {
                    $rejected[] = ['code' => $code, 'reason' => $reason, 'min' => $c === null ? 0 : (int) $c->min_spend_minor];

                    continue;
                }

                if ($c->type === 'free_shipping') {
                    $freeShipping = true;
                    $applied[] = new AppliedDiscount('coupon', $code, $code, 0, true);

                    continue;
                }

                $amount = $this->amount($c, $base);
                if ($amount <= 0) {
                    $rejected[] = ['code' => $code, 'reason' => 'no_discount'];

                    continue;
                }

                $hasDiscountCoupon = true;
                foreach ($this->spread($amount, $eligible) as $variantId => $part) {
                    $lineDiscounts[$variantId] = ($lineDiscounts[$variantId] ?? 0) + $part;
                }
                $applied[] = new AppliedDiscount('coupon', $code, $code, $amount);
            }
        }

        $order = 0;
        if (($promo = $this->bestPromotion($request, $now)) !== null) {
            $order = $promo['amount'];
            $applied[] = new AppliedDiscount('promotion', null, $promo['label'], $promo['amount']);
        }

        return new DiscountOutcome($lineDiscounts, $order, $freeShipping, $applied, $rejected);
    }

    private function unusable(\stdClass $c, string $now, int $usedByThisPerson, bool $knowsWho): ?string
    {
        return match (true) {
            ! $c->is_active => 'inactive',
            $c->starts_at !== null && (string) $c->starts_at > $now => 'not_started',
            $c->ends_at !== null && (string) $c->ends_at <= $now => 'expired',
            $c->usage_limit !== null && (int) $c->used_count >= (int) $c->usage_limit => 'exhausted',
            $knowsWho && $c->per_customer_limit !== null && $usedByThisPerson >= (int) $c->per_customer_limit => 'per_customer_limit',
            default => null,
        };
    }

    /**
     * @param  array{products?: list<int>, categories?: list<int>}  $scope
     * @param  list<DiscountLine>  $lines
     * @return list<DiscountLine>
     */
    private function eligible(\stdClass $c, array $scope, array $lines): array
    {
        return array_values(array_filter($lines, fn (DiscountLine $l) => match ($c->scope) {
            'products' => in_array($l->productId, $scope['products'] ?? [], true),
            'categories' => array_intersect($l->categoryIds, $scope['categories'] ?? []) !== [],
            default => true,
        } && $l->totalMinor > 0));
    }

    /**
     * @param  array<string, \stdClass>  $coupons
     * @return array<int, array{products?: list<int>, categories?: list<int>}>
     */
    private function scopes(array $coupons): array
    {
        $out = [];
        $ids = array_values(array_map(fn (\stdClass $c) => (int) $c->id, array_filter($coupons, fn (\stdClass $c) => $c->scope !== 'all')));
        if ($ids === []) {
            return $out;
        }

        foreach (DB::table('coupon_products')->whereIn('coupon_id', $ids)->get() as $r) {
            $out[(int) $r->coupon_id]['products'][] = (int) $r->product_id;
        }
        foreach (DB::table('coupon_categories')->whereIn('coupon_id', $ids)->get() as $r) {
            $out[(int) $r->coupon_id]['categories'][] = (int) $r->category_id;
        }

        return $out;
    }

    private function amount(\stdClass $c, int $base): int
    {
        $amount = $c->type === 'percent' ? Money::of($base)->percentage((int) $c->value)->amountMinor : (int) $c->value;

        if ($c->max_discount_minor !== null) {
            $amount = min($amount, (int) $c->max_discount_minor);
        }

        return max(0, min($amount, $base));
    }

    /**
     * @param  list<DiscountLine>  $eligible
     * @return array<int, int>
     */
    private function spread(int $amount, array $eligible): array
    {
        $parts = Money::of($amount)->allocate(array_map(fn (DiscountLine $l) => $l->totalMinor, $eligible));
        $out = [];
        foreach ($eligible as $i => $l) {
            if ($parts[$i]->amountMinor > 0) {
                $out[$l->variantId] = $parts[$i]->amountMinor;
            }
        }

        return $out;
    }

    /** @return array{amount: int, label: string}|null */
    private function bestPromotion(DiscountRequest $request, string $now): ?array
    {
        $subtotal = array_sum(array_map(fn (DiscountLine $l) => $l->totalMinor, $request->lines));
        if ($subtotal <= 0) {
            return null;
        }

        $default = (string) config('app.default_locale');
        $best = null;

        $promos = DB::table('promotions as p')
            ->leftJoin('promotion_translations as tl', fn ($j) => $j->on('tl.promotion_id', '=', 'p.id')->where('tl.locale', $request->locale))
            ->leftJoin('promotion_translations as td', fn ($j) => $j->on('td.promotion_id', '=', 'p.id')->where('td.locale', $default))
            ->where('p.is_active', true)->where('p.min_spend_minor', '<=', $subtotal)
            ->where(fn ($q) => $q->whereNull('p.starts_at')->orWhere('p.starts_at', '<=', $now))->where(fn ($q) => $q->whereNull('p.ends_at')->orWhere('p.ends_at', '>', $now))
            ->get(['p.id', 'p.type', 'p.value', 'p.max_discount_minor', DB::raw('COALESCE(tl.label, td.label) as label')]);

        foreach ($promos as $p) {
            $amount = $this->amount($p, $subtotal);
            if ($amount > 0 && ($best === null || $amount > $best['amount'])) {
                $best = ['amount' => $amount, 'label' => (string) ($p->label ?? '')];
            }
        }

        return $best;
    }
}
