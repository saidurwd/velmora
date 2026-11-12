<?php

declare(strict_types=1);

namespace App\Modules\Platform\Enums;

/**
 * Stable machine codes for API problem+json (BD §12). Never rename or reuse a code once released.
 * Module-specific codes (stock.insufficient, payment.verification_failed, ...) are added by their modules.
 */
enum ErrorCode: string
{
    case ValidationFailed = 'validation.failed';
    case Unauthenticated = 'auth.unauthenticated';
    case Forbidden = 'auth.forbidden';
    case NotFound = 'resource.not_found';
    case MethodNotAllowed = 'http.method_not_allowed';
    case RateLimited = 'rate.limited';
    case ServerError = 'server.error';
    case IdempotencyKeyRequired = 'idempotency.key_required';
    case IdempotencyKeyInvalid = 'idempotency.key_invalid';
    case IdempotencyConflict = 'idempotency.conflict';
    case IdempotencyInProgress = 'idempotency.in_progress';
    case AppUpgradeRequired = 'app.upgrade_required';
    case InvalidCredentials = 'auth.invalid_credentials';
    case OtpInvalid = 'auth.otp_invalid';
    case RefreshInvalid = 'auth.refresh_invalid';
    case RefreshReused = 'auth.refresh_reused';
    case ReauthRequired = 'auth.reauth_required';
    case OtpUnavailable = 'auth.otp_unavailable';
    case ProductNotFound = 'product.not_found';
    case PageNotFound = 'page.not_found';
    case StockInsufficient = 'stock.insufficient';
    case CartQuantityLimit = 'cart.quantity_limit';
    case CartTooManyLines = 'cart.too_many_lines';
    case CartItemUnavailable = 'cart.item_unavailable';
    case CartNeedsBooking = 'cart.needs_booking';
    case CartItemNotFound = 'cart.item_not_found';
    case CouponInvalid = 'coupon.invalid';
    case CartEmpty = 'cart.empty';
    case CartProblem = 'cart.problem';
    case AddressRequired = 'address.required';
    case AddressInvalid = 'address.invalid';
    case AddressLimit = 'address.limit_reached';
    case DeliveryUnavailable = 'delivery.unavailable';
    case ShippingMethodInvalid = 'shipping.method_invalid';
    case PaymentMethodInvalid = 'payment.method_invalid';
    case TotalsChanged = 'checkout.totals_changed';
    case CodNotEligible = 'cod.not_eligible';
    case PhoneConfirmationRequired = 'checkout.phone_confirmation_required';
    case PhoneInvalid = 'checkout.phone_invalid';
    case CheckoutNotAccepted = 'checkout.not_accepted';
    case CheckoutBusy = 'checkout.busy';
    case BookingHoldExpired = 'booking.hold_expired';
    case PoliciesRequired = 'policies.required';
    case PoliciesChanged = 'policies.changed';

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed => 422,
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::AppUpgradeRequired => 426,
            self::InvalidCredentials, self::OtpInvalid, self::RefreshInvalid, self::RefreshReused => 401,
            self::ReauthRequired => 403,
            self::OtpUnavailable => 503,
            self::ProductNotFound, self::PageNotFound => 404,
            self::StockInsufficient, self::CartProblem, self::TotalsChanged, self::CheckoutBusy, self::BookingHoldExpired, self::PoliciesChanged => 409,
            self::CartQuantityLimit, self::CartTooManyLines, self::CartItemUnavailable, self::CartNeedsBooking, self::CouponInvalid, self::CartEmpty, self::AddressRequired, self::AddressInvalid, self::AddressLimit, self::DeliveryUnavailable, self::ShippingMethodInvalid, self::PaymentMethodInvalid, self::CodNotEligible, self::PhoneInvalid, self::CheckoutNotAccepted, self::PoliciesRequired => 422,
            self::CartItemNotFound => 404,
            self::PhoneConfirmationRequired => 403,
            self::RateLimited => 429,
            self::ServerError => 500,
            self::IdempotencyKeyRequired, self::IdempotencyKeyInvalid => 400,
            self::IdempotencyConflict, self::IdempotencyInProgress => 409,
        };
    }

    /** Translation key for the human title (localised by Accept-Language). */
    public function titleKey(): ?string
    {
        return match ($this) {
            self::ValidationFailed => 'errors.api.validation_failed',
            self::Unauthenticated => 'errors.api.unauthenticated',
            self::Forbidden => 'errors.api.forbidden',
            self::NotFound => 'errors.api.not_found',
            self::MethodNotAllowed => 'errors.api.method_not_allowed',
            self::RateLimited => 'errors.api.rate_limited',
            self::ServerError => 'errors.api.server_error',
            self::AppUpgradeRequired => 'errors.api.app_upgrade_required',
            self::InvalidCredentials => 'errors.api.invalid_credentials',
            self::OtpInvalid => 'errors.api.otp_invalid',
            self::RefreshInvalid => 'errors.api.refresh_invalid',
            self::RefreshReused => 'errors.api.refresh_reused',
            self::ReauthRequired => 'errors.api.reauth_required',
            self::OtpUnavailable => 'errors.api.otp_unavailable',
            self::ProductNotFound => 'errors.api.product_not_found',
            self::PageNotFound => 'errors.api.page_not_found',
            self::StockInsufficient => 'errors.api.stock_insufficient',
            self::CartQuantityLimit => 'errors.api.cart_quantity_limit',
            self::CartTooManyLines => 'errors.api.cart_too_many_lines',
            self::CartItemUnavailable => 'errors.api.cart_item_unavailable',
            self::CartNeedsBooking => 'errors.api.cart_needs_booking',
            self::CartItemNotFound => 'errors.api.cart_item_not_found',
            self::CouponInvalid => 'errors.api.coupon_invalid',
            self::CartEmpty => 'errors.api.cart_empty',
            self::CartProblem => 'errors.api.cart_problem',
            self::AddressRequired => 'errors.api.address_required',
            self::AddressInvalid => 'errors.api.address_invalid',
            self::AddressLimit => 'errors.api.address_limit',
            self::DeliveryUnavailable => 'errors.api.delivery_unavailable',
            self::ShippingMethodInvalid => 'errors.api.shipping_invalid',
            self::PaymentMethodInvalid => 'errors.api.payment_invalid',
            self::TotalsChanged => 'errors.api.totals_changed',
            self::CodNotEligible => 'errors.api.cod_not_eligible',
            self::PhoneConfirmationRequired => 'errors.api.phone_confirmation_required',
            self::PhoneInvalid => 'errors.api.phone_invalid',
            self::CheckoutNotAccepted => 'errors.api.not_accepted',
            self::CheckoutBusy => 'errors.api.checkout_busy',
            self::BookingHoldExpired => 'errors.api.booking_expired',
            self::PoliciesRequired => 'errors.api.policies_required',
            self::PoliciesChanged => 'errors.api.policies_changed',
            default => null,
        };
    }
}
