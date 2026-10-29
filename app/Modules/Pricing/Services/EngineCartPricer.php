<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Contracts\CartPricer;
use App\Modules\Pricing\Contracts\PriceCatalog;
use App\Modules\Pricing\Contracts\TaxRates;
use App\Modules\Pricing\DTOs\PriceBreakdown;
use App\Modules\Pricing\DTOs\PricingContext;
use App\Modules\Pricing\Exceptions\PricingException;

/** Reads live prices (sales included) and tax settings once, then hands plain numbers to the calculator. */
final class EngineCartPricer implements CartPricer
{
    public function __construct(
        private readonly PriceCatalog $prices,
        private readonly TaxRates $taxRates,
        private readonly Settings $settings,
        private readonly BreakdownCalculator $calculator,
    ) {}

    public function price(PricingContext $context): PriceBreakdown
    {
        $quotes = $this->prices->quoteMany(array_column(array_map(fn ($l) => ['id' => $l->variantId, 'class' => $l->taxClassCode], $context->lines), 'class', 'id'));

        $lines = [];
        foreach ($context->lines as $l) {
            $q = $quotes[$l->variantId] ?? throw PricingException::noPrice($l->variantId);
            $lines[] = ['variantId' => $l->variantId, 'quantity' => $l->quantity, 'unitMinor' => $q->price->amountMinor, 'lineDiscountMinor' => $l->lineDiscountMinor, 'taxRateBp' => $q->taxRateBp];
        }

        $rate = $this->taxRates->rateBp('standard'); // 0 while tax is switched off
        $inclusive = $this->settings->bool('tax.prices_include_tax', true);

        return $this->calculator->calculate(
            $lines, $inclusive, $context->orderDiscountMinor, $context->shippingMinor, $context->codFeeMinor,
            $this->settings->bool('tax.shipping_taxable', false) ? $rate : 0,
            $this->settings->bool('tax.cod_fee_taxable', false) ? $rate : 0,
        );
    }
}
