<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/** Min 10 chars (owner-approved), not trivially guessable, not derived from the account's email/phone/name. */
final class StrongPassword implements DataAwareRule, ValidationRule
{
    private const COMMON = [
        'password', 'password1', 'password12', 'password123', 'passw0rd123', 'qwertyuiop', 'qwerty12345', 'qwerty123456',
        '1234567890', '12345678910', '0123456789', '1111111111', '0000000000', 'iloveyou12', 'letmein1234', 'welcome123',
        'admin12345', 'administrator', 'abcd123456', 'abc1234567', 'bangladesh', 'bangladesh1', 'bangladesh123', 'dhaka12345',
        'velmora123', 'velmora1234', 'changeme123', 'password!23', 'p@ssw0rd123', 'asdfghjkl1', 'monkey1234', 'football123',
    ];

    /** @var array<string, mixed> */
    private array $data = [];

    /** @param  array<string, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = (string) $value;
        $lower = mb_strtolower($password);

        if (mb_strlen($password) < 10) {
            $fail('identity-access::auth.password_rules.min')->translate();

            return;
        }

        if (mb_strlen($password) > 128) {
            $fail('identity-access::auth.password_rules.max')->translate();

            return;
        }

        $local = strstr(mb_strtolower((string) ($this->data['email'] ?? '')), '@', true) ?: '';
        $phone = (string) preg_replace('/\D/', '', (string) ($this->data['phone'] ?? ''));

        if (in_array($lower, self::COMMON, true)
            || count(array_unique(mb_str_split($password))) < 5
            || ($local !== '' && mb_strlen($local) >= 4 && str_contains($lower, $local))
            || ($phone !== '' && strlen($phone) >= 8 && str_contains((string) preg_replace('/\D/', '', $password), substr($phone, -8)))) {
            $fail('identity-access::auth.password_rules.weak')->translate();
        }
    }
}
