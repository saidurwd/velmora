<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\FrozenClock;
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Actions\BulkChangePrices;
use App\Modules\Pricing\Actions\ScheduleSale;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Contracts\PriceCatalog;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Pricing\DTOs\PriceQuote;
use App\Modules\Pricing\Exceptions\PricingException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PriceListSeeder::class, TaxClassSeeder::class]);
    $this->actor = Actor::system('test');
    $category = app(SaveCategory::class)->handle($this->actor, null, null, [new TranslationInput('en', 'Clothing')]);
    $this->variantIds = [];
    foreach (['A', 'B', 'C'] as $i => $letter) {
        $product = app(SaveProduct::class)->handle($this->actor, null, new ProductData(ProductType::Physical, [new TranslationInput('en', "Item {$letter}")], categoryIds: [$category->id], defaultVariant: new VariantData("ITEM-{$letter}1", trackInventory: true)));
        $this->variantIds[] = (int) $product->variants()->value('id');
    }
    $this->v = $this->variantIds[0];
    $this->prices = app(PriceCatalog::class);
});

function setPrice(int $variantId, int $minor, ?int $compare = null): void
{
    app(SetRegularPrice::class)->handle(Actor::system('test'), $variantId, Money::of($minor), $compare === null ? null : Money::of($compare));
}

function enableTax(int $bp = 1500, bool $inclusive = true): void
{
    $standard = DB::table('tax_classes')->where('code', 'standard')->value('id');
    DB::table('tax_rates')->insert(['tax_class_id' => $standard, 'jurisdiction' => 'BD', 'rate_bp' => $bp, 'valid_from' => '2020-01-01', 'valid_to' => '9999-12-31', 'created_at' => now(), 'updated_at' => now()]);
    app(Settings::class)->set('tax.enabled', true);
    app(Settings::class)->set('tax.prices_include_tax', $inclusive);
}

describe('regular price', function () {
    it('stores money as integer poisha and quotes it', function () {
        setPrice($this->v, 125000, 150000);

        $quote = $this->prices->quote($this->v, 'standard');

        expect($quote->price->amountMinor)->toBe(125000)->and($quote->regular->amountMinor)->toBe(125000)->and($quote->compareAt?->amountMinor)->toBe(150000)
            ->and($quote->onSale)->toBeFalse()->and($quote->discountPercent())->toBe(17)->and($quote->price->format('en'))->toBe('৳ 1,250.00');
    });

    it('updates in place and audits before and after', function () {
        setPrice($this->v, 100000);
        setPrice($this->v, 90000);

        $audit = DB::table('audit_events')->where('action', 'price.set')->orderByDesc('id')->first();
        expect(DB::table('prices')->count())->toBe(1)->and(json_decode($audit->before, true)['amount_minor'])->toBe(100000)->and(json_decode($audit->after, true)['amount_minor'])->toBe(90000);
    });

    it('rejects a compare-at price that is not higher, in the action and in the database', function () {
        expect(fn () => setPrice($this->v, 100000, 100000))->toThrow(PricingException::class, 'higher');

        setPrice($this->v, 100000);
        expect(fn () => DB::table('prices')->update(['compare_at_minor' => 50000]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('prices')->update(['amount_minor' => -1]))->toThrow(QueryException::class);
    });

    it('rejects a currency that does not match the price list', function () {
        expect(fn () => app(SetRegularPrice::class)->handle($this->actor, $this->v, Money::of(1000, 'USD')))->toThrow(PricingException::class, 'uses BDT');
    });

    it('throws a clear error when a variant has no price', function () {
        expect(fn () => $this->prices->quote($this->v, 'standard'))->toThrow(PricingException::class, 'has no price');
    });

    it('reports whether every variant has a price (the publish rule)', function () {
        setPrice($this->variantIds[0], 1000);
        setPrice($this->variantIds[1], 1000);

        expect($this->prices->allHaveRegularPrice([$this->variantIds[0], $this->variantIds[1]]))->toBeTrue()
            ->and($this->prices->allHaveRegularPrice($this->variantIds))->toBeFalse()
            ->and($this->prices->allHaveRegularPrice([]))->toBeFalse();
    });
});

describe('scheduled sales', function () {
    it('applies the sale inside its window and returns to the regular price when it ends', function () {
        setPrice($this->v, 100000);
        app(ScheduleSale::class)->handle($this->actor, $this->v, Money::of(80000), '2026-12-01 00:00', '2026-12-08 00:00'); // Dhaka time

        $at = fn (string $utc) => $this->prices->quote($this->v, 'standard', CarbonImmutable::parse($utc, 'UTC'));

        expect($at('2026-11-30 17:59:59')->onSale)->toBeFalse()   // 23:59:59 on 30 Nov in Dhaka
            ->and($at('2026-11-30 18:00:00')->price->amountMinor)->toBe(80000)   // midnight 1 Dec in Dhaka: sale starts
            ->and($at('2026-11-30 18:00:00')->compareAt?->amountMinor)->toBe(100000) // the regular price is shown as "was"
            ->and($at('2026-12-07 17:59:59')->onSale)->toBeTrue()
            ->and($at('2026-12-07 18:00:00')->onSale)->toBeFalse()   // exactly midnight 8 Dec in Dhaka: back to regular
            ->and($at('2026-12-07 18:00:00')->price->amountMinor)->toBe(100000);
    });

    it('exposes the sale end time for countdowns', function () {
        setPrice($this->v, 100000);
        app(ScheduleSale::class)->handle($this->actor, $this->v, Money::of(80000), '2026-12-01 00:00', '2026-12-08 00:00');

        expect($this->prices->quote($this->v, 'standard', CarbonImmutable::parse('2026-12-02', 'UTC'))->saleEndsAt?->toIso8601String())->toBe('2026-12-07T18:00:00+00:00');
    });

    it('never lets two sales overlap, in the action and in the database, but allows back-to-back sales', function () {
        setPrice($this->v, 100000);
        $sales = app(ScheduleSale::class);
        $sales->handle($this->actor, $this->v, Money::of(80000), '2026-12-01 00:00', '2026-12-08 00:00');

        expect(fn () => $sales->handle($this->actor, $this->v, Money::of(70000), '2026-12-07 00:00', '2026-12-10 00:00'))->toThrow(PricingException::class, 'already exists');

        $sales->handle($this->actor, $this->v, Money::of(70000), '2026-12-08 00:00', '2026-12-10 00:00'); // starts the instant the other ends

        $row = DB::table('price_sales')->first();
        expect(fn () => DB::table('price_sales')->insert(['variant_id' => $this->v, 'price_list_id' => $row->price_list_id, 'amount_minor' => 1, 'currency' => 'BDT', 'starts_at' => '2026-12-02 00:00:00', 'ends_at' => '2026-12-03 00:00:00', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
    });

    it('rejects a sale that is not cheaper, a backwards window, or a variant without a price', function () {
        setPrice($this->v, 100000);
        $sales = app(ScheduleSale::class);

        expect(fn () => $sales->handle($this->actor, $this->v, Money::of(100000), '2026-12-01 00:00', '2026-12-02 00:00'))->toThrow(PricingException::class)
            ->and(fn () => $sales->handle($this->actor, $this->v, Money::of(5000), '2026-12-02 00:00', '2026-12-01 00:00'))->toThrow(PricingException::class, 'end after')
            ->and(fn () => $sales->handle($this->actor, $this->variantIds[1], Money::of(5000), '2026-12-01 00:00', '2026-12-02 00:00'))->toThrow(PricingException::class, 'no price');
    });

    it('cancels a sale and audits it', function () {
        setPrice($this->v, 100000);
        $id = app(ScheduleSale::class)->handle($this->actor, $this->v, Money::of(80000), '2026-12-01 00:00', '2026-12-08 00:00');

        app(ScheduleSale::class)->cancel($this->actor, $id);

        expect(DB::table('price_sales')->count())->toBe(0)->and(DB::table('audit_events')->whereIn('action', ['price.sale_scheduled', 'price.sale_cancelled'])->count())->toBe(2)
            ->and($this->prices->quote($this->v, 'standard', CarbonImmutable::parse('2026-12-02', 'UTC'))->onSale)->toBeFalse();
    });

    it('uses the injected clock when no time is given', function () {
        setPrice($this->v, 100000);
        app(ScheduleSale::class)->handle($this->actor, $this->v, Money::of(80000), '2026-12-01 00:00', '2026-12-08 00:00');
        app()->instance(Clock::class, new FrozenClock('2026-12-03 12:00:00'));

        expect(app(PriceCatalog::class)->quote($this->v, 'standard')->onSale)->toBeTrue();
    });
});

describe('tax in quotes', function () {
    it('adds no tax while tax is disabled', function () {
        setPrice($this->v, 100000);

        $line = $this->prices->quote($this->v, 'standard')->line(2);

        expect($line->tax->amountMinor)->toBe(0)->and($line->gross->amountMinor)->toBe(200000)->and($line->net->amountMinor)->toBe(200000);
    });

    it('extracts tax from a tax-inclusive price', function () {
        enableTax(1500, inclusive: true);
        setPrice($this->v, 115000);

        $line = $this->prices->quote($this->v, 'standard')->line(1);

        expect($line->gross->amountMinor)->toBe(115000)->and($line->tax->amountMinor)->toBe(15000)->and($line->net->amountMinor)->toBe(100000);
    });

    it('adds tax on top of a tax-exclusive price', function () {
        enableTax(1500, inclusive: false);
        setPrice($this->v, 100000);

        $line = $this->prices->quote($this->v, 'standard')->line(1);

        expect($line->net->amountMinor)->toBe(100000)->and($line->tax->amountMinor)->toBe(15000)->and($line->gross->amountMinor)->toBe(115000);
    });

    it('taxes the sale price, not the regular price, while on sale', function () {
        enableTax(1500, inclusive: false);
        setPrice($this->v, 100000);
        app(ScheduleSale::class)->handle($this->actor, $this->v, Money::of(80000), '2026-12-01 00:00', '2026-12-08 00:00');

        $line = $this->prices->quote($this->v, 'standard', CarbonImmutable::parse('2026-12-02', 'UTC'))->line(1);

        expect($line->tax->amountMinor)->toBe(12000)->and($line->gross->amountMinor)->toBe(92000);
    });

    it('never loses a paisa: net + tax = gross for random prices, quantities, rates and both modes', function () {
        mt_srand(20261028);

        foreach ([true, false] as $inclusive) {
            foreach (range(1, 400) as $i) {
                $bp = [0, 500, 750, 1000, 1500, 1725][mt_rand(0, 5)];
                $price = mt_rand(1, 99_999_999);
                $quote = new PriceQuote(1, 'retail', Money::of($price), Money::of($price), null, false, null, $inclusive, $bp);
                $line = $quote->line(mt_rand(1, 50));

                expect($line->net->add($line->tax)->amountMinor)->toBe($line->gross->amountMinor)
                    ->and($line->tax->amountMinor)->toBeGreaterThanOrEqual(0)
                    ->and($line->net->amountMinor)->toBeGreaterThanOrEqual(0);

                if ($inclusive) {
                    expect($line->gross->amountMinor)->toBe($price * ($line->gross->amountMinor / $price)); // gross is exactly the priced total
                }
            }
        }
    });

    it('taxes the line total once, so 3 × ৳0.99 does not accumulate rounding', function () {
        $quote = new PriceQuote(1, 'retail', Money::of(99), Money::of(99), null, false, null, false, 1500);

        $perUnit = $quote->line(1)->tax->amountMinor * 3;
        $perLine = $quote->line(3)->tax->amountMinor;

        expect($perLine)->toBe(45)->and($perUnit)->toBe(45); // 14.85 -> 15 each = 45; line: 297 * 15% = 44.55 -> 45
    });
});

describe('bulk price change', function () {
    beforeEach(function () {
        setPrice($this->variantIds[0], 100000);
        setPrice($this->variantIds[1], 99900);
        setPrice($this->variantIds[2], 5000, 8000);
    });

    it('previews exact old → new prices without changing anything', function () {
        $preview = app(BulkChangePrices::class)->preview($this->variantIds, 1000);

        $byVariant = collect($preview->rows)->keyBy('variant_id');
        expect($byVariant[$this->variantIds[0]]['new_minor'])->toBe(110000)->and($byVariant[$this->variantIds[1]]['new_minor'])->toBe(109890)
            ->and($byVariant[$this->variantIds[2]]['new_minor'])->toBe(5500)->and($byVariant[$this->variantIds[2]]['warning'])->toBeNull()
            ->and(DB::table('prices')->where('variant_id', $this->variantIds[0])->value('amount_minor'))->toBe(100000);
    });

    it('rounds to the nearest ৳5 when asked, and flags prices that reach the compare-at price', function () {
        $preview = app(BulkChangePrices::class)->preview($this->variantIds, 6000, roundToMinor: 500); // +60%

        $byVariant = collect($preview->rows)->keyBy('variant_id');
        expect($byVariant[$this->variantIds[1]]['new_minor'] % 500)->toBe(0)
            ->and($byVariant[$this->variantIds[2]]['new_minor'])->toBe(8000)
            ->and($byVariant[$this->variantIds[2]]['warning'])->toBe('at or above the compare-at price');
    });

    it('applies every row atomically and audits each', function () {
        $bulk = app(BulkChangePrices::class);
        $applied = $bulk->apply($this->actor, $bulk->preview($this->variantIds, -2000));

        expect($applied)->toBe(3)->and(DB::table('prices')->where('variant_id', $this->variantIds[0])->value('amount_minor'))->toBe(80000)
            ->and(DB::table('audit_events')->where('action', 'price.bulk_changed')->count())->toBe(3);
    });

    it('refuses to apply a preview when prices changed after it was made', function () {
        $bulk = app(BulkChangePrices::class);
        $preview = $bulk->preview($this->variantIds, 1000);

        setPrice($this->variantIds[1], 12345); // someone else edits meanwhile

        expect(fn () => $bulk->apply($this->actor, $preview))->toThrow(PricingException::class, 'preview again')
            ->and(DB::table('prices')->where('variant_id', $this->variantIds[0])->value('amount_minor'))->toBe(100000); // nothing was applied
    });

    it('refuses an empty change and never produces a negative price', function () {
        $bulk = app(BulkChangePrices::class);

        expect(fn () => $bulk->apply($this->actor, $bulk->preview($this->variantIds, 0)))->toThrow(PricingException::class, 'nothing to change');

        $cut = $bulk->preview($this->variantIds, -15000); // -150%
        expect(collect($cut->rows)->min('new_minor'))->toBe(0);
    });
});
