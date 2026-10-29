<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Exceptions\CartException;
use App\Modules\Cart\Models\CartCoupon;
use App\Modules\Cart\Services\CartRepository;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\CouponCodes;
use App\Modules\Platform\Support\Fmt;
use App\Modules\Platform\Support\Transactions;

/** Adds a code to the cart if it works for these items today; otherwise says why not and leaves the cart as it was. */
final class ApplyCoupon
{
    public const MAX_CODES = 2;

    public function __construct(private readonly CartRepository $carts, private readonly CartReader $reader) {}

    /** @throws CartException  reason `coupon` carries the explanation in params['message'] */
    public function handle(CartOwner $owner, string $typed, string $locale): void
    {
        $code = CouponCodes::normalise($typed);
        if ($code === '') {
            throw new CartException('coupon', ['message' => __('pricing::coupons.reasons.unknown', ['code' => trim($typed) === '' ? '—' : mb_substr(trim($typed), 0, 64)])]);
        }

        $cart = $this->carts->find($owner) ?? throw new CartException('coupon', ['message' => __('cart::cart.coupon_empty_cart')]);

        Transactions::run(function () use ($cart, $owner, $code, $locale): void {
            $cart->newQuery()->whereKey($cart->id)->lockForUpdate()->first();
            $existing = CartCoupon::query()->where('cart_id', $cart->id)->pluck('code')->map(fn ($c) => (string) $c)->all();

            if (in_array($code, $existing, true)) {
                return; // typed twice: already there
            }
            if (count($existing) >= self::MAX_CODES) {
                throw new CartException('coupon', ['message' => __('pricing::coupons.reasons.too_many')]);
            }

            $discounts = $this->reader->viewWith($owner, $locale, array_values([...$existing, $code]))->discounts;
            foreach ($discounts === null ? [] : $discounts->rejected as $r) {
                if ($r['code'] === $code) {
                    throw new CartException('coupon', ['message' => __('pricing::coupons.reasons.'.$r['reason'], ['code' => $code, 'min' => Fmt::money(Money::of($r['min'] ?? 0))])]);
                }
            }

            CartCoupon::query()->create(['cart_id' => $cart->id, 'code' => $code]);
            $this->carts->touch($cart);
        });
    }
}
