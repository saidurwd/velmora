<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/** The guest cart's cookie: a random token (HttpOnly, SameSite=Lax, 30 days). Only its hash is stored in the database. */
final class GuestCartToken
{
    public const COOKIE = 'velmora_cart';

    public const DAYS = 30;

    public function fromRequest(Request $request): ?string
    {
        $token = $request->cookie(self::COOKIE);

        return is_string($token) && preg_match('/^[a-f0-9]{40}$/', $token) === 1 ? $token : null;
    }

    public function issue(): string
    {
        $token = bin2hex(random_bytes(20));
        Cookie::queue(Cookie::make(self::COOKIE, $token, 60 * 24 * self::DAYS, '/', null, request()->isSecure(), true, false, 'lax'));

        return $token;
    }

    public function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
