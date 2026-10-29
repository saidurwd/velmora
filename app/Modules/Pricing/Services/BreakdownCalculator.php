<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Platform\DTOs\Money;
use App\Modules\Pricing\DTOs\BreakdownLine;
use App\Modules\Pricing\DTOs\PriceBreakdown;

/**
 * The arithmetic of an order total, with NO database and NO settings: numbers in, numbers out. That is what makes it testable
 * with random inputs, and what lets an order's totals be recomputed later from its stored line snapshots alone.
 *
 * Rules: discounts are clamped to what they apply to; the order discount is split across lines in proportion to what each
 * costs after its own discount (exact to the paisa, `Money::allocate`); tax is taken once per line on the discounted line
 * total, never unit by unit; shipping and COD fee carry tax only when told to.
 */
final class BreakdownCalculator
{
    /**
     * @param  list<array{variantId: int, quantity: int, unitMinor: int, lineDiscountMinor: int, taxRateBp: int}>  $lines
     */
    public function calculate(array $lines, bool $taxInclusive, int $orderDiscountMinor, int $shippingMinor, int $codFeeMinor, int $shippingTaxRateBp = 0, int $codFeeTaxRateBp = 0, string $currency = 'BDT'): PriceBreakdown
    {
        $shippingMinor = max(0, $shippingMinor);
        $codFeeMinor = max(0, $codFeeMinor);

        $gross = [];
        $lineDiscount = [];
        foreach ($lines as $i => $l) {
            $gross[$i] = max(0, $l['unitMinor']) * max(0, $l['quantity']);
            $lineDiscount[$i] = min($gross[$i], max(0, $l['lineDiscountMinor']));
        }

        $afterLine = array_map(fn (int $g, int $d) => $g - $d, $gross, $lineDiscount);
        $orderDiscountMinor = min(max(0, $orderDiscountMinor), array_sum($afterLine));
        $share = $this->spread($orderDiscountMinor, $afterLine, $currency);

        $out = [];
        $taxTotal = 0;
        foreach ($lines as $i => $l) {
            $total = $afterLine[$i] - $share[$i];
            $tax = $this->taxOn($total, $l['taxRateBp'], $taxInclusive, $currency);
            $taxTotal += $tax;

            $out[] = new BreakdownLine($l['variantId'], $l['quantity'], $l['unitMinor'], $gross[$i], $lineDiscount[$i], $share[$i], $total, $l['taxRateBp'], $tax);
        }

        $extra = [];
        foreach ([['shipping', $shippingMinor, $shippingTaxRateBp], ['cod_fee', $codFeeMinor, $codFeeTaxRateBp]] as [$kind, $amount, $rate]) {
            if ($rate > 0 && $amount > 0) {
                $tax = $this->taxOn($amount, $rate, $taxInclusive, $currency);
                $extra[] = ['kind' => $kind, 'rate_bp' => $rate, 'taxable_minor' => $taxInclusive ? $amount - $tax : $amount, 'tax_minor' => $tax];
                $taxTotal += $tax;
            }
        }

        $subtotal = array_sum($gross);
        $lineDiscounts = array_sum($lineDiscount);
        $grand = $subtotal - $lineDiscounts - $orderDiscountMinor + $shippingMinor + $codFeeMinor + ($taxInclusive ? 0 : $taxTotal);

        return new PriceBreakdown($currency, $taxInclusive, $out, $subtotal, $lineDiscounts, $orderDiscountMinor, $shippingMinor, $codFeeMinor, $taxTotal, $grand, $extra);
    }

    /**
     * @param  list<int>  $weights
     * @return list<int>
     */
    private function spread(int $amount, array $weights, string $currency): array
    {
        if ($amount <= 0 || array_sum($weights) <= 0) {
            return array_fill(0, count($weights), 0);
        }

        return array_map(fn (Money $m) => $m->amountMinor, Money::of($amount, $currency)->allocate($weights));
    }

    private function taxOn(int $amount, int $rateBp, bool $inclusive, string $currency): int
    {
        if ($rateBp <= 0 || $amount <= 0) {
            return 0;
        }

        $money = Money::of($amount, $currency);

        return ($inclusive ? $money->multiplyRatio($rateBp, 10000 + $rateBp) : $money->percentage($rateBp))->amountMinor;
    }
}
