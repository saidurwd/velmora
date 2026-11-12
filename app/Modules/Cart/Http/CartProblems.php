<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http;

use App\Modules\Cart\Exceptions\CartException;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;

/** Turns what a cart Action refused into a stable code, with the website's own wording as the explanation and the numbers an app may need under `params`. */
final class CartProblems
{
    public static function from(CartException $e): ApiProblem
    {
        $code = match ($e->reason) {
            'insufficient_stock' => ErrorCode::StockInsufficient,
            'min_qty', 'max_qty', 'qty_step' => ErrorCode::CartQuantityLimit,
            'too_many_lines' => ErrorCode::CartTooManyLines,
            'needs_booking', 'one_booking' => ErrorCode::CartNeedsBooking,
            'not_in_cart' => ErrorCode::CartItemNotFound,
            'coupon' => ErrorCode::CouponInvalid,
            default => ErrorCode::CartItemUnavailable,
        };

        return new ApiProblem($code, $e->params === [] ? [] : ['params' => $e->params], detail: __('cart::cart.errors.'.$e->reason, $e->params));
    }
}
