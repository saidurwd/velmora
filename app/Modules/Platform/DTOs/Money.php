<?php

declare(strict_types=1);

namespace App\Modules\Platform\DTOs;

use App\Modules\Platform\Exceptions\MoneyException;
use App\Modules\Platform\Support\Grouping;
use JsonSerializable;

/**
 * Immutable money in integer minor units (BD-20 / ADR-004). No floats anywhere.
 * BDT: 1 taka = 100 poisha, so Money::of(125000) is ৳ 1,250.00.
 */
final readonly class Money implements JsonSerializable
{
    /** Decimal places per currency (extend when a currency is enabled). */
    private const EXPONENTS = ['BDT' => 2, 'USD' => 2, 'JPY' => 0];

    private const SYMBOLS = ['BDT' => '৳', 'USD' => '$', 'JPY' => '¥'];

    private function __construct(public int $amountMinor, public string $currency) {}

    public static function of(int $amountMinor, string $currency = 'BDT'): self
    {
        $currency = strtoupper($currency);

        if (! isset(self::EXPONENTS[$currency])) {
            throw MoneyException::invalid("Unsupported currency [{$currency}].");
        }

        return new self($amountMinor, $currency);
    }

    public static function zero(string $currency = 'BDT'): self
    {
        return self::of(0, $currency);
    }

    /** Parse a decimal string such as "1250.5" or "১২৫০.৫০" without touching floats. */
    public static function fromMajorString(string $amount, string $currency = 'BDT'): self
    {
        $exponent = self::EXPONENTS[strtoupper($currency)] ?? throw MoneyException::invalid("Unsupported currency [{$currency}].");
        $amount = strtr(trim($amount), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9', ',' => '']);

        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $amount, $m)) {
            throw MoneyException::invalid("Cannot parse amount [{$amount}].");
        }

        $fraction = $m[3] ?? '';

        if (strlen($fraction) > $exponent) {
            throw MoneyException::invalid("Too many decimal places for {$currency}: [{$amount}].");
        }

        $digits = $m[2].str_pad($fraction, $exponent, '0');

        if (strlen(ltrim($digits, '0')) > 18) {
            throw MoneyException::overflow();
        }

        $minor = (int) $digits;

        return self::of($m[1] === '-' ? -$minor : $minor, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->amountMinor + $other->amountMinor), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->amountMinor - $other->amountMinor), $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self(self::checked($this->amountMinor * $factor), $this->currency);
    }

    /** Multiply by numerator/denominator, rounding half away from zero (exactly once). */
    public function multiplyRatio(int $numerator, int $denominator): self
    {
        if ($denominator <= 0) {
            throw MoneyException::invalid('Denominator must be positive.');
        }

        $product = self::checked($this->amountMinor * $numerator);
        $quotient = intdiv(abs($product), $denominator);

        if (2 * (abs($product) % $denominator) >= $denominator) {
            $quotient++;
        }

        return new self($product < 0 ? -$quotient : $quotient, $this->currency);
    }

    /** Percentage in basis points (1500 = 15%). */
    public function percentage(int $basisPoints): self
    {
        return $this->multiplyRatio($basisPoints, 10000);
    }

    /**
     * Split without losing a minor unit: the parts always sum to the original amount.
     * Remainder units go one each to the earliest parts (largest-remainder by position).
     *
     * @param  list<int>  $ratios  non-negative weights, at least one > 0
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        $total = array_sum($ratios);

        if ($ratios === [] || $total <= 0 || min($ratios) < 0) {
            throw MoneyException::invalid('Allocation ratios must be non-negative with a positive sum.');
        }

        $sign = $this->amountMinor < 0 ? -1 : 1;
        $abs = abs($this->amountMinor);
        $shares = [];
        $allocated = 0;

        foreach ($ratios as $ratio) {
            $share = intdiv(self::checked($abs * $ratio), $total);
            $shares[] = $share;
            $allocated += $share;
        }

        for ($i = 0, $remainder = $abs - $allocated; $remainder > 0; $i = ($i + 1) % count($shares)) {
            if ($ratios[$i] > 0) {
                $shares[$i]++;
                $remainder--;
            }
        }

        return array_values(array_map(fn (int $share) => new self($sign * $share, $this->currency), $shares));
    }

    public function negate(): self
    {
        return new self(self::checked(-$this->amountMinor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor <=> $other->amountMinor;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amountMinor === $other->amountMinor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    /**
     * Locale-aware display: en = "৳ 1,250.00"; bn = lakh/crore grouping ("৳ 12,50,000.00"),
     * optionally with Bangla digits (BD-35: display option only, storage stays ASCII).
     */
    /** Plain decimal in major units for form fields, e.g. "1250.50" (no grouping, no float). */
    public function toMajorString(): string
    {
        $exponent = self::EXPONENTS[$this->currency];
        $digits = str_pad((string) abs($this->amountMinor), $exponent + 1, '0', STR_PAD_LEFT);
        $text = $exponent === 0 ? $digits : substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);

        return ($this->amountMinor < 0 ? '-' : '').$text;
    }

    public function format(string $locale = 'en', bool $banglaDigits = false): string
    {
        $exponent = self::EXPONENTS[$this->currency];
        $abs = (string) abs($this->amountMinor);
        $abs = str_pad($abs, $exponent + 1, '0', STR_PAD_LEFT);
        $integer = $exponent > 0 ? substr($abs, 0, -$exponent) : $abs;
        $fraction = $exponent > 0 ? substr($abs, -$exponent) : '';

        $grouped = Grouping::forLocale($integer, $locale);
        $text = ($this->amountMinor < 0 ? '-' : '').self::SYMBOLS[$this->currency].' '.$grouped.($fraction !== '' ? '.'.$fraction : '');

        return $banglaDigits ? strtr($text, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']) : $text;
    }

    /** @return array{amount_minor: int, currency: string, formatted: string} */
    public function jsonSerialize(): array
    {
        return ['amount_minor' => $this->amountMinor, 'currency' => $this->currency, 'formatted' => $this->format()];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw MoneyException::currencyMismatch($this->currency, $other->currency);
        }
    }

    private static function checked(int|float $value): int
    {
        if (! is_int($value)) {
            throw MoneyException::overflow();
        }

        return $value;
    }
}
