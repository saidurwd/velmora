<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/**
 * The tax billed on the orders placed in one time window, for one kind of charge ('goods', 'shipping' or 'cod_fee') at one rate. `taxInclusive` says whether the prices of
 * those orders already contained the tax. `taxableMinor` is the amount the tax was worked out on (for tax-inclusive prices: the price without the tax). Cancelled orders are left out.
 */
final readonly class TaxSalesRow
{
    public function __construct(
        public string $kind,
        public int $rateBp,
        public string $currency,
        public bool $taxInclusive,
        public int $lines,
        public int $taxableMinor,
        public int $taxMinor,
    ) {}
}
