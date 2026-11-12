<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Platform\Support\SignedToken;

/**
 * "This phone number was confirmed with a code a moment ago", as a token the app holds (the website keeps the same fact in its session). Bound to ONE phone
 * number (only its hash is inside) and good for `orders.phone_proof_minutes`. It proves nothing else: it opens no account and no order, it only lifts the
 * "confirm your phone" rule of a large cash order for that number.
 */
final class PhoneProof
{
    private const PURPOSE = 'phone-proof';

    public function __construct(private readonly SignedToken $tokens) {}

    public function issue(string $phoneE164): string
    {
        return $this->tokens->issue(self::PURPOSE, ['h' => $this->phoneHash($phoneE164)], $this->seconds());
    }

    public function valid(?string $proof, string $phoneE164): bool
    {
        $claims = $this->tokens->read(self::PURPOSE, $proof);

        return $claims !== null && hash_equals($this->phoneHash($phoneE164), (string) ($claims['h'] ?? ''));
    }

    public function seconds(): int
    {
        return max(60, (int) config('orders.phone_proof_minutes', 30) * 60);
    }

    private function phoneHash(string $phone): string
    {
        return hash('sha256', 'phone|'.$phone);
    }
}
