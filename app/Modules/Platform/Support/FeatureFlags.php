<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use InvalidArgumentException;

/**
 * Switches staff may turn on and off without a deployment. The list is in code on purpose: a flag exists only if a developer made one, and
 * nothing that moves real money (live payments) is on it. A value staff set wins; otherwise the environment's default applies.
 */
final class FeatureFlags
{
    /** Flag => the config key holding its default (the environment). */
    public const FLAGS = [
        'cart_recovery' => 'engagement.cart_recovery.enabled',
    ];

    public function __construct(private readonly Settings $settings) {}

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::FLAGS);
    }

    public static function settingKey(string $flag): string
    {
        isset(self::FLAGS[$flag]) || throw new InvalidArgumentException("Unknown feature flag [{$flag}].");

        return 'feature.'.$flag;
    }

    public function enabled(string $flag): bool
    {
        $set = $this->settings->get(self::settingKey($flag));

        return $set === null ? (bool) config(self::FLAGS[$flag]) : filter_var($set, FILTER_VALIDATE_BOOLEAN);
    }

    /** True when staff have set the flag, false when it still follows the environment's default. */
    public function isSetByStaff(string $flag): bool
    {
        return $this->settings->get(self::settingKey($flag)) !== null;
    }

    public function environmentDefault(string $flag): bool
    {
        isset(self::FLAGS[$flag]) || throw new InvalidArgumentException("Unknown feature flag [{$flag}].");

        return (bool) config(self::FLAGS[$flag]);
    }
}
