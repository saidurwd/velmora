<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/**
 * Keeps a mail server chosen in the admin area from pointing the application at its own network: no "localhost", no private or reserved addresses.
 * On a developer's machine (local, testing) a local mail catcher is the whole point, so the rule is off there.
 */
final class HostGuard
{
    /** The name or address alone: fine to store. Does not look anything up. */
    public static function nameAllowed(string $host): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }
        if (strcasecmp($host, 'localhost') === 0 || str_ends_with(strtolower($host), '.local') || str_ends_with(strtolower($host), '.internal')) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) === false || self::publicIp($host);
    }

    /** Every address the name points to must be a public one. Checked again when the test runs, because DNS can change after a name was saved. */
    public static function reachable(string $host): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }
        if (! self::nameAllowed($host)) {
            return false;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (array) @gethostbynamel($host);

        return $addresses !== [] && array_reduce($addresses, fn (bool $ok, mixed $ip) => $ok && is_string($ip) && self::publicIp($ip), true);
    }

    private static function publicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
