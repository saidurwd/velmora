<?php

declare(strict_types=1);

namespace App\Modules\Platform\DTOs;

use App\Modules\Platform\Exceptions\InvalidPhoneNumber;
use App\Modules\Platform\Support\BanglaText;
use Stringable;

/**
 * Single entry point for phone numbers (E.164 storage; Bangladesh rules).
 * Accepts 01XXXXXXXXX, 8801XXXXXXXXX, +8801XXXXXXXXX, 008801XXXXXXXXX, with Bangla digits and separators.
 * Bangladesh mobile = +8801[3-9]XXXXXXXX [ASSUMPTION: confirm operator prefixes against current BTRC numbering].
 */
final readonly class PhoneNumber implements Stringable
{
    private function __construct(private string $e164) {}

    public static function parse(string $raw, bool $allowInternational = false): self
    {
        $value = BanglaText::numeric($raw);
        $value = (string) preg_replace('/[().\-]/', '', $value);

        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        }

        if (preg_match('/^01[3-9]\d{8}$/', $value)) {
            return new self('+88'.$value);
        }

        if (preg_match('/^8801[3-9]\d{8}$/', $value)) {
            return new self('+'.$value);
        }

        if (preg_match('/^\+8801[3-9]\d{8}$/', $value)) {
            return new self($value);
        }

        if ($allowInternational && preg_match('/^\+[1-9]\d{7,14}$/', $value) && ! str_starts_with($value, '+880')) {
            return new self($value);
        }

        throw new InvalidPhoneNumber('Invalid phone number.');
    }

    public static function tryParse(string $raw, bool $allowInternational = false): ?self
    {
        try {
            return self::parse($raw, $allowInternational);
        } catch (InvalidPhoneNumber) {
            return null;
        }
    }

    public function e164(): string
    {
        return $this->e164;
    }

    public function isBangladeshi(): bool
    {
        return str_starts_with($this->e164, '+880');
    }

    /** Local format 01XXXXXXXXX for Bangladeshi numbers; E.164 otherwise. */
    public function national(): string
    {
        return $this->isBangladeshi() ? '0'.substr($this->e164, 4) : $this->e164;
    }

    /** Log-safe: +88017****1234. */
    public function masked(): string
    {
        return substr($this->e164, 0, 6).str_repeat('*', max(0, strlen($this->e164) - 10)).substr($this->e164, -4);
    }

    public function equals(self $other): bool
    {
        return $this->e164 === $other->e164;
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
