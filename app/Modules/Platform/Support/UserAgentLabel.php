<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/**
 * A short, readable device label from a browser's user-agent string ("Chrome on Windows", "Velmora app on Android"), for login history and sessions.
 * Deliberately rough: enough for a person to recognise their own device, nothing that fingerprints it.
 */
final class UserAgentLabel
{
    /** @return array{browser: string, os: string} empty strings when unknown */
    public static function parse(?string $ua): array
    {
        $ua = (string) $ua;
        if ($ua === '') {
            return ['browser' => '', 'os' => ''];
        }

        $browser = match (true) {
            (bool) preg_match('/okhttp|Dart\/|CFNetwork|Velmora/i', $ua) => 'app',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            (bool) preg_match('/curl|wget|python|httpie|Guzzle|Symfony/i', $ua) => 'script',
            default => '',
        };
        $os = match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };

        return ['browser' => $browser, 'os' => $os];
    }

    /** "Chrome on Windows", in the current language; "Unknown device" when nothing is recognised. */
    public static function label(?string $ua): string
    {
        ['browser' => $b, 'os' => $o] = self::parse($ua);
        $b = match ($b) {
            'app' => (string) __('platform::security.device.app'),
            'script' => (string) __('platform::security.device.script'),
            default => $b,
        };

        return match (true) {
            $b !== '' && $o !== '' => (string) __('platform::security.device.on', ['browser' => $b, 'os' => $o]),
            $b !== '' => $b,
            $o !== '' => $o,
            default => (string) __('platform::security.device.unknown'),
        };
    }
}
