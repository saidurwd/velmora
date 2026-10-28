<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Mfa;

/** Ten-character single-use recovery codes (≈50 bits each), stored only as keyed hashes. */
final class RecoveryCodes
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789'; // no look-alikes (i, l, o, 0, 1)

    public const COUNT = 8;

    /** @return list<string> e.g. "k3m9x-w2pqa" */
    public static function generate(): array
    {
        $codes = [];

        for ($i = 0; $i < self::COUNT; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return $codes;
    }

    public static function normalise(string $input): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $input));
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', self::normalise($code), (string) config('app.key'));
    }

    public static function looksLikeRecoveryCode(string $input): bool
    {
        return strlen(self::normalise($input)) === 10 && preg_match('/[a-z]/i', $input) === 1;
    }
}
