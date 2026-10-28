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

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed => 422,
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
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
            default => null,
        };
    }
}
