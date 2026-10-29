<?php

declare(strict_types=1);

use App\Modules\Pricing\DTOs\PriceBreakdown;
use App\Modules\Pricing\Services\BreakdownCalculator;

/** @return list<array{variantId: int, quantity: int, unitMinor: int, lineDiscountMinor: int, taxRateBp: int}> */
function lines(array ...$rows): array
{
    return array_map(fn (array $r, int $i) => ['variantId' => $i + 1, 'quantity' => $r[1], 'unitMinor' => $r[0], 'lineDiscountMinor' => $r[2] ?? 0, 'taxRateBp' => $r[3] ?? 0], $rows, array_keys($rows));
}

function calc(array $lines, bool $inclusive = true, int $orderDiscount = 0, int $shipping = 0, int $cod = 0, int $shipRate = 0, int $codRate = 0): PriceBreakdown
{
    return (new BreakdownCalculator)->calculate($lines, $inclusive, $orderDiscount, $shipping, $cod, $shipRate, $codRate);
}

describe('worked examples (checked by hand)', function () {
    it('splits an order discount to the paisa, then taxes each discounted line once — tax added on top', function () {
        // A: ৳100.00 × 2 = 20000   B: ৳50.50 × 1 = 5050   order discount ৳25.00   shipping ৳60.00   VAT 15% added
        $b = calc(lines([10000, 2, 0, 1500], [5050, 1, 0, 1500]), inclusive: false, orderDiscount: 2500, shipping: 6000);

        expect(array_map(fn ($l) => $l->orderDiscountShareMinor, $b->lines))->toBe([1997, 503])   // 2500 in proportion 20000 : 5050, the spare paisa to the first line
            ->and(array_map(fn ($l) => $l->totalMinor, $b->lines))->toBe([18003, 4547])
            ->and(array_map(fn ($l) => $l->taxMinor, $b->lines))->toBe([2700, 682])               // 18003 × 15% = 2700.45, 4547 × 15% = 682.05
            ->and($b->subtotalMinor)->toBe(25050)->and($b->discountMinor())->toBe(2500)->and($b->taxMinor)->toBe(3382)
            ->and($b->grandTotalMinor)->toBe(25050 - 2500 + 6000 + 3382)->and($b->grandTotalMinor)->toBe(31932)
            ->and($b->reconciles())->toBeTrue();
    });

    it('same order when prices already include VAT: tax is inside the total, not added', function () {
        $b = calc(lines([10000, 2, 0, 1500], [5050, 1, 0, 1500]), inclusive: true, orderDiscount: 2500, shipping: 6000);

        expect(array_map(fn ($l) => $l->taxMinor, $b->lines))->toBe([2348, 593])                   // 18003 × 1500/11500, 4547 × 1500/11500
            ->and($b->taxMinor)->toBe(2941)->and($b->grandTotalMinor)->toBe(25050 - 2500 + 6000)->and($b->grandTotalMinor)->toBe(28550)
            ->and($b->reconciles())->toBeTrue();
    });

    it('applies a line discount first, and an order discount to what is left', function () {
        $b = calc(lines([10000, 1, 3000], [10000, 1, 0]), orderDiscount: 1700);

        expect(array_map(fn ($l) => [$l->lineDiscountMinor, $l->orderDiscountShareMinor, $l->totalMinor], $b->lines))->toBe([[3000, 700, 6300], [0, 1000, 9000]])  // 1700 split 7000 : 10000
            ->and($b->grandTotalMinor)->toBe(15300);
    });

    it('charges VAT on shipping and the COD fee only when told to', function () {
        $plain = calc(lines([10000, 1, 0, 1500]), inclusive: false, shipping: 6000, cod: 2000);
        $taxed = calc(lines([10000, 1, 0, 1500]), inclusive: false, shipping: 6000, cod: 2000, shipRate: 1500, codRate: 1500);

        expect($plain->taxMinor)->toBe(1500)->and($plain->grandTotalMinor)->toBe(10000 + 6000 + 2000 + 1500)
            ->and($taxed->taxMinor)->toBe(1500 + 900 + 300)->and($taxed->grandTotalMinor)->toBe(10000 + 6000 + 2000 + 2700)
            ->and(array_column($taxed->extraTaxLines, 'kind'))->toBe(['shipping', 'cod_fee'])->and($taxed->reconciles())->toBeTrue();
    });
});

describe('edge cases', function () {
    it('never discounts more than the goods cost', function () {
        $b = calc(lines([10000, 1, 99999], [5000, 2]), orderDiscount: 9_999_999, shipping: 6000);

        expect($b->lines[0]->lineDiscountMinor)->toBe(10000)->and($b->orderDiscountMinor)->toBe(10000)->and(array_map(fn ($l) => $l->totalMinor, $b->lines))->toBe([0, 0])
            ->and($b->grandTotalMinor)->toBe(6000)->and($b->reconciles())->toBeTrue();
    });

    it('prices an empty order, zero-price lines and negative inputs sensibly', function () {
        expect(calc([])->grandTotalMinor)->toBe(0)
            ->and(calc(lines([0, 3]), orderDiscount: 500)->grandTotalMinor)->toBe(0)
            ->and(calc(lines([1000, 1]), orderDiscount: -500, shipping: -100, cod: -5)->grandTotalMinor)->toBe(1000);
    });

    it('is the same answer every time', function () {
        $l = lines([3333, 3, 100, 1500], [777, 7, 0, 1500], [1, 1, 0, 0]);

        expect(calc($l, false, 400, 6000, 100, 1500))->toEqual(calc($l, false, 400, 6000, 100, 1500));
    });
});

describe('properties over thousands of random orders', function () {
    it('always add up, never go negative, and never lose or invent a paisa', function () {
        mt_srand(20261029);
        $rates = [0, 0, 500, 750, 1500];

        for ($n = 0; $n < 4000; $n++) {
            $rows = [];
            $rate = $rates[array_rand($rates)];
            foreach (range(1, mt_rand(1, 8)) as $_) {
                $unit = mt_rand(0, 8) === 0 ? 0 : mt_rand(1, 500_000);
                $qty = mt_rand(1, 30);
                $rows[] = [$unit, $qty, mt_rand(0, 3) === 0 ? mt_rand(0, (int) ($unit * $qty * 1.2)) : 0, mt_rand(0, 9) === 0 ? 0 : $rate];
            }
            $merch = array_sum(array_map(fn ($r) => $r[0] * $r[1], $rows));
            $inclusive = (bool) mt_rand(0, 1);
            $orderDiscount = mt_rand(0, 2) === 0 ? mt_rand(0, (int) ($merch * 1.3) + 1) : 0;
            $shipping = mt_rand(0, 30_000);
            $cod = mt_rand(0, 3) === 0 ? mt_rand(0, 10_000) : 0;
            $shipRate = mt_rand(0, 1) ? $rate : 0;
            $codRate = mt_rand(0, 1) ? $rate : 0;

            $b = calc(lines(...$rows), $inclusive, $orderDiscount, $shipping, $cod, $shipRate, $codRate);
            $ctx = json_encode([$rows, $inclusive, $orderDiscount, $shipping, $cod, $shipRate, $codRate]);

            expect($b->reconciles())->toBeTrue("does not reconcile: {$ctx}");
            expect($b->grandTotalMinor)->toBeGreaterThanOrEqual(0, $ctx);
            expect($b->taxMinor)->toBeGreaterThanOrEqual(0, $ctx);
            expect($b->orderDiscountMinor)->toBeLessThanOrEqual(max(0, $merch - $b->lineDiscountMinor), $ctx);

            foreach ($b->lines as $l) {
                expect($l->totalMinor)->toBeGreaterThanOrEqual(0, $ctx)
                    ->and($l->lineDiscountMinor)->toBeLessThanOrEqual($l->grossMinor, $ctx)
                    ->and($l->grossMinor - $l->lineDiscountMinor - $l->orderDiscountShareMinor)->toBe($l->totalMinor, $ctx)
                    ->and($l->taxMinor)->toBeLessThanOrEqual($l->totalMinor, $ctx)
                    ->and($l->taxRateBp > 0 || $l->taxMinor === 0)->toBeTrue($ctx);
            }

            // a discount that covers the goods leaves no goods to tax
            if ($b->orderDiscountMinor + $b->lineDiscountMinor === $b->subtotalMinor) {
                expect(array_sum(array_map(fn ($l) => $l->taxMinor, $b->lines)))->toBe(0, $ctx);
            }
            // exclusive and inclusive pricing must treat the same pre-tax money identically on the discount side
            expect($calcTwice = calc(lines(...$rows), $inclusive, $orderDiscount, $shipping, $cod, $shipRate, $codRate))->toEqual($b);
        }
    });

    it('splits any discount exactly across any lines', function () {
        mt_srand(77);
        for ($n = 0; $n < 2000; $n++) {
            $rows = [];
            foreach (range(1, mt_rand(1, 10)) as $_) {
                $rows[] = [mt_rand(1, 99_999), mt_rand(1, 9)];
            }
            $merch = array_sum(array_map(fn ($r) => $r[0] * $r[1], $rows));
            $discount = mt_rand(0, $merch);

            $b = calc(lines(...$rows), orderDiscount: $discount);

            expect(array_sum(array_map(fn ($l) => $l->orderDiscountShareMinor, $b->lines)))->toBe($discount);
            foreach ($b->lines as $l) {
                // a line's share is within one paisa of its exact proportional share
                $exact = $discount * $l->grossMinor / $merch;
                expect(abs($l->orderDiscountShareMinor - $exact))->toBeLessThan(1.0 + 1e-9);
            }
        }
    });
});
