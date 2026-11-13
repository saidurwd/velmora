<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/**
 * Turns the TRUSTED_PROXIES setting into what Laravel's proxy middleware understands. Behind a load balancer the visitor's real address arrives in X-Forwarded-For, but only from a
 * proxy we trust; without that, every visitor looks like the load balancer and shares ONE rate-limit bucket (and every log line shows the balancer's address).
 *
 *   empty            → nobody is trusted (the header is ignored)
 *   *  or  **        → trust whoever is calling (right only when the app is reachable ONLY through the load balancer)
 *   10.0.0.1, 10.0.0.0/8 → exactly these addresses / ranges
 *
 * A wildcard must stay the plain string: a list that merely CONTAINS "*" is not a wildcard to Laravel and trusts nobody.
 */
final class TrustedProxies
{
    /** @return string|list<string>|null */
    public static function parse(string|bool|null $setting): string|array|null
    {
        if (! is_string($setting)) {
            return null;   // unset (or something that is not an address list): nobody is trusted
        }
        $setting = trim($setting);
        if ($setting === '') {
            return null;
        }
        if ($setting === '*' || $setting === '**') {
            return $setting;
        }

        $list = array_values(array_filter(array_map('trim', explode(',', $setting)), fn (string $ip): bool => $ip !== ''));

        return $list === [] ? null : $list;
    }
}
