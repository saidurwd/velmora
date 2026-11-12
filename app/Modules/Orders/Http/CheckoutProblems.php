<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http;

use App\Modules\Orders\Exceptions\CheckoutException;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;

/** Turns what checkout refused into a stable code, with the website's own wording as the explanation. Anything it does not know is answered as an order that cannot be accepted, never as a crash. */
final class CheckoutProblems
{
    /** @param  array<string, mixed>  $extra */
    public static function from(CheckoutException $e, array $extra = []): ApiProblem
    {
        $code = match ($e->reason) {
            'cart_empty' => ErrorCode::CartEmpty,
            'cart_problem' => ErrorCode::CartProblem,
            'address_missing', 'address_required' => ErrorCode::AddressRequired,
            'cannot_deliver' => ErrorCode::DeliveryUnavailable,
            'shipping_method_invalid' => ErrorCode::ShippingMethodInvalid,
            'payment_method_invalid' => ErrorCode::PaymentMethodInvalid,
            'totals_changed' => ErrorCode::TotalsChanged,
            'too_many_open_cod' => ErrorCode::CodNotEligible,
            'phone_confirmation_required' => ErrorCode::PhoneConfirmationRequired,
            'phone_invalid' => ErrorCode::PhoneInvalid,
            'busy' => ErrorCode::CheckoutBusy,
            'booking_lost' => ErrorCode::BookingHoldExpired,
            'insufficient_stock' => ErrorCode::StockInsufficient,
            'coupon_failed' => ErrorCode::CouponInvalid,
            'policies_required' => ErrorCode::PoliciesRequired,
            'policies_changed' => ErrorCode::PoliciesChanged,
            default => ErrorCode::CheckoutNotAccepted,
        };

        $params = $e->params === [] ? [] : ['params' => $e->params];

        return new ApiProblem($code, $params + $extra, $e->reason === 'busy' ? ['Retry-After' => '2'] : [], __('orders::checkout.errors.'.$e->reason, $e->params));
    }
}
