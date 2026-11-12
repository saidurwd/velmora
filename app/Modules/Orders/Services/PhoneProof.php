<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Platform\Contracts\Clock;

/**
 * "This phone number was confirmed with a code a moment ago", as a token the app holds (the website keeps the same fact in its session). Signed with a key
 * made from the app key, bound to ONE phone number (only its hash is inside), and good for `orders.phone_proof_minutes`. It proves nothing else: it opens
 * no account and no order, it only lifts the "confirm your phone" rule of a large cash order for that number.
 */
final class PhoneProof
{
    public function __construct(private readonly Clock $clock) {}

    public function issue(string $phoneE164): string
    {
        $payload = $this->b64(json_encode(['h' => $this->phoneHash($phoneE164), 'e' => $this->clock->now()->getTimestamp() + $this->seconds()], JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->b64($this->sign($payload));
    }

    public function valid(?string $proof, string $phoneE164): bool
    {
        if ($proof === null || substr_count($proof, '.') !== 1) {
            return false;
        }
        [$payload, $signature] = explode('.', $proof);

        if (! hash_equals($this->sign($payload), (string) base64_decode(strtr($signature, '-_', '+/'), true))) {
            return false;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        return is_array($data) && hash_equals($this->phoneHash($phoneE164), (string) ($data['h'] ?? '')) && (int) ($data['e'] ?? 0) >= $this->clock->now()->getTimestamp();
    }

    public function seconds(): int
    {
        return max(60, (int) config('orders.phone_proof_minutes', 30) * 60);
    }

    private function phoneHash(string $phone): string
    {
        return hash('sha256', 'phone|'.$phone);
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, hash('sha256', 'phone-proof|'.config('app.key'), true), true);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
