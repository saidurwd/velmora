<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

use App\Modules\Platform\DTOs\Money;

/**
 * The price of an order, in minor units, with every part visible. The parts always add up:
 *   grand total = subtotal − discounts + shipping + COD fee + (tax, only when prices are tax-exclusive)
 * which is exactly the CHECK on `orders` (BANGLADESH_LOCALIZATION §5). When prices include tax, `taxMinor` is the part of the
 * total that is tax (for the invoice), not an addition.
 *
 * @phpstan-type TaxLine array{kind: string, rate_bp: int, taxable_minor: int, tax_minor: int}
 */
final readonly class PriceBreakdown
{
    /**
     * @param  list<BreakdownLine>  $lines
     * @param  list<array{kind: string, rate_bp: int, taxable_minor: int, tax_minor: int}>  $extraTaxLines  tax on shipping / COD fee when those are taxable
     */
    public function __construct(
        public string $currency,
        public bool $taxInclusive,
        public array $lines,
        public int $subtotalMinor,
        public int $lineDiscountMinor,
        public int $orderDiscountMinor,
        public int $shippingMinor,
        public int $codFeeMinor,
        public int $taxMinor,
        public int $grandTotalMinor,
        public array $extraTaxLines = [],
    ) {}

    public function discountMinor(): int
    {
        return $this->lineDiscountMinor + $this->orderDiscountMinor;
    }

    public function grandTotal(): Money
    {
        return Money::of($this->grandTotalMinor, $this->currency);
    }

    /** The accounting identity, recomputed from the parts. Anything that stores a breakdown can assert this. */
    public function reconciles(): bool
    {
        $expected = $this->subtotalMinor - $this->discountMinor() + $this->shippingMinor + $this->codFeeMinor + ($this->taxInclusive ? 0 : $this->taxMinor);
        $linesTax = array_sum(array_map(fn (BreakdownLine $l) => $l->taxMinor, $this->lines)) + array_sum(array_column($this->extraTaxLines, 'tax_minor'));

        return $expected === $this->grandTotalMinor
            && $linesTax === $this->taxMinor
            && array_sum(array_map(fn (BreakdownLine $l) => $l->orderDiscountShareMinor, $this->lines)) === $this->orderDiscountMinor
            && array_sum(array_map(fn (BreakdownLine $l) => $l->lineDiscountMinor, $this->lines)) === $this->lineDiscountMinor
            && array_sum(array_map(fn (BreakdownLine $l) => $l->grossMinor, $this->lines)) === $this->subtotalMinor;
    }
}
