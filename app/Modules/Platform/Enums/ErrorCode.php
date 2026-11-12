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
            default => null,
        };
    }
}
