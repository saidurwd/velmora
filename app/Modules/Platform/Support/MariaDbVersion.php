<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/** Reads what `SELECT VERSION()` says and tells whether it is a MariaDB this shop can run on (the schema needs 11.4.5 or newer: application-time periods and the uca1400 collation). */
final class MariaDbVersion
{
    public const MINIMUM = '11.4.5';

    /** @return array{engine: string, version: string}|null null when the text is not a version at all */
    public static function parse(string $reported): ?array
    {
        $text = preg_replace('/^5\.5\.5-/', '', trim($reported)) ?? '';   // older PDO drivers put a fake MySQL version in front of MariaDB's
        if (preg_match('/^(\d+\.\d+\.\d+)/', $text, $m) !== 1) {
            return null;
        }

        return ['engine' => stripos($text, 'mariadb') !== false ? 'mariadb' : 'mysql', 'version' => $m[1]];
    }

    public static function isSupported(string $reported): bool
    {
        $parsed = self::parse($reported);

        return $parsed !== null && $parsed['engine'] === 'mariadb' && version_compare($parsed['version'], self::MINIMUM, '>=');
    }
}
