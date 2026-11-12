<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Contracts\Clock;

/**
 * A small signed, expiring token for "this holder was given this one thing" (a confirmed phone, a guest's order). It is not a login: it carries a
 * purpose and a few claims, is signed with a key made from the app key AND the purpose (a token for one purpose never reads as another), and stops
 * working at its expiry. Anything wrong with it (damaged, wrong purpose, expired, never ours) simply reads as nothing.
 */
final class SignedToken
{
    public function __construct(private readonly Clock $clock) {}

    /** @param  array<string, scalar|null>  $claims */
    public function issue(string $purpose, array $claims, int $seconds): string
    {
        $payload = $this->b64(json_encode(['p' => $purpose, 'c' => $claims, 'e' => $this->clock->now()->getTimestamp() + max(1, $seconds)], JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->b64($this->sign($purpose, $payload));
    }

    /** @return array<string, scalar|null>|null the claims, or null when this is not a good token for this purpose right now */
    public function read(string $purpose, ?string $token): ?array
    {
        if ($token === null || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $signature] = explode('.', $token);

        $given = base64_decode(strtr($signature, '-_', '+/'), true);
        if ($given === false || ! hash_equals($this->sign($purpose, $payload), $given)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        if (! is_array($data) || ($data['p'] ?? null) !== $purpose || (int) ($data['e'] ?? 0) < $this->clock->now()->getTimestamp() || ! is_array($data['c'] ?? null)) {
            return null;
        }

        return $data['c'];
    }

    private function sign(string $purpose, string $payload): string
    {
        return hash_hmac('sha256', $payload, hash('sha256', 'signed-token|'.$purpose.'|'.config('app.key'), true), true);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
