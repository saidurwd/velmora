<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

use App\Modules\Platform\DTOs\Money;
use Carbon\CarbonImmutable;

/**
 * What one unit of a variant costs right now. Cart, checkout and the API all use this one object, so a price is never
 * computed twice in two places. `price` is what the customer pays per unit as the shop prices it (tax-inclusive when
 * `pricesIncludeTax`), `regular` the list price, `compareAt` an optional struck-through price.
 */
final readonly class PriceQuote
{
    public function __construct(
        public int $variantId,
        public string $listCode,
        public Money $price,
        public Money $regular,
        public ?Money $compareAt,
        public bool $onSale,
        public ?CarbonImmutable $saleEndsAt,
        public bool $pricesIncludeTax,
        public int $taxRateBp,
    ) {}

    /** Amounts for $quantity units, taxed once on the line total (not unit-by-unit) so rounding never accumulates. */
    public function line(int $quantity): LineAmounts
    {
        $total = $this->price->multiply($quantity);

        if ($this->pricesIncludeTax) {
            $tax = $total->multiplyRatio($this->taxRateBp, 10000 + $this->taxRateBp);

            return new LineAmounts($total->subtract($tax), $tax, $total);
        }

        $tax = $total->percentage($this->taxRateBp);

        return new LineAmounts($total, $tax, $total->add($tax));
    }

    /** Percentage saved against the compare-at price, in whole percent (for a "-20%" badge). */
    public function discountPercent(): ?int
    {
        if ($this->compareAt === null || $this->compareAt->amountMinor === 0) {
            return null;
        }

        return (int) round(100 * ($this->compareAt->amountMinor - $this->price->amountMinor) / $this->compareAt->amountMinor);
    }
}
