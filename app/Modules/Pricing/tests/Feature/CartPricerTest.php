<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Actions\ScheduleSale;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Contracts\CartPricer;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Pricing\DTOs\PricingContext;
use App\Modules\Pricing\DTOs\PricingLine;
use App\Modules\Pricing\Exceptions\PricingException;
use App\Modules\Pricing\Services\BreakdownCalculator;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class]);
    $this->actor = Actor::system('t');
    $category = app(SaveCategory::class)->handle($this->actor, null, null, [new TranslationInput('en', 'General', 'general')]);
    $this->variant = function (string $sku, int $minor, string $taxClass = 'standard') use ($category): int {
        $p = app(SaveProduct::class)->handle($this->actor, null, new ProductData(ProductType::Physical, [new TranslationInput('en', "Item {$sku}")], taxClassCode: $taxClass, categoryIds: [$category->id], defaultVariant: new VariantData($sku)));
        $id = (int) $p->variants()->value('id');
        app(SetRegularPrice::class)->handle($this->actor, $id, Money::of($minor));

        return $id;
    };
    $this->a = ($this->variant)('A-1', 10000);          // ৳100.00
    $this->b = ($this->variant)('B-1', 5050);           // ৳50.50
    $this->z = ($this->variant)('Z-1', 20000, 'zero_rated');
    $this->vat = function (bool $inclusive, int $bp = 1500): void {
        $standard = DB::table('tax_classes')->where('code', 'standard')->value('id');
        DB::table('tax_rates')->insert(['tax_class_id' => $standard, 'jurisdiction' => 'BD', 'rate_bp' => $bp, 'valid_from' => '2020-01-01', 'valid_to' => '9999-12-31', 'created_at' => now(), 'updated_at' => now()]);
        app(Settings::class)->set('tax.enabled', true);
        app(Settings::class)->set('tax.prices_include_tax', $inclusive);
    };
    $this->price = fn (PricingContext $c) => app(CartPricer::class)->price($c);
    $this->ctx = fn (array $lines, int $discount = 0, int $ship = 0, int $cod = 0) => new PricingContext($lines, $discount, $ship, $cod);
});

it('prices a cart from live prices when tax is off (the owner is not VAT-registered yet)', function () {
    $b = ($this->price)(($this->ctx)([new PricingLine($this->a, 'standard', 2), new PricingLine($this->b, 'standard', 1)], 2500, 6000, 0));

    expect($b->taxMinor)->toBe(0)->and($b->subtotalMinor)->toBe(25050)->and($b->grandTotalMinor)->toBe(25050 - 2500 + 6000)->and($b->reconciles())->toBeTrue()
        ->and(array_map(fn ($l) => $l->unitPriceMinor, $b->lines))->toBe([10000, 5050]);
});

it('adds VAT on top when prices exclude it, using the rate in force', function () {
    ($this->vat)(false);
    $b = ($this->price)(($this->ctx)([new PricingLine($this->a, 'standard', 2), new PricingLine($this->b, 'standard', 1), new PricingLine($this->z, 'zero_rated', 1)], 2500, 6000));

    // the worked example from the unit test, plus one zero-rated line that is taxed at 0
    expect($b->lines[0]->taxRateBp)->toBe(1500)->and($b->lines[2]->taxRateBp)->toBe(0)->and($b->lines[2]->taxMinor)->toBe(0)
        ->and($b->reconciles())->toBeTrue()->and($b->taxInclusive)->toBeFalse()
        ->and($b->grandTotalMinor)->toBe($b->subtotalMinor - 2500 + 6000 + $b->taxMinor);
});

it('shows the VAT inside the price when prices include it, without changing the total', function () {
    ($this->vat)(true);
    $b = ($this->price)(($this->ctx)([new PricingLine($this->a, 'standard', 2)], 0, 6000));

    expect($b->taxInclusive)->toBeTrue()->and($b->taxMinor)->toBe(2609)                 // 20000 × 1500/11500 = 2608.7
        ->and($b->grandTotalMinor)->toBe(26000)->and($b->reconciles())->toBeTrue();
});

it('uses the sale price while a sale runs', function () {
    app(ScheduleSale::class)->handle($this->actor, $this->a, Money::of(8000), now('Asia/Dhaka')->subHour()->format('Y-m-d H:i'), now('Asia/Dhaka')->addDay()->format('Y-m-d H:i'));
    $b = ($this->price)(($this->ctx)([new PricingLine($this->a, 'standard', 3)]));

    expect($b->lines[0]->unitPriceMinor)->toBe(8000)->and($b->grandTotalMinor)->toBe(24000);
});

it('taxes shipping and the COD fee only when the owner has said they are taxable', function () {
    ($this->vat)(false);
    $lines = [new PricingLine($this->a, 'standard', 1)];

    $off = ($this->price)(($this->ctx)($lines, 0, 6000, 2000));
    expect($off->taxMinor)->toBe(1500)->and($off->extraTaxLines)->toBe([]);

    app(Settings::class)->set('tax.shipping_taxable', true);
    app(Settings::class)->set('tax.cod_fee_taxable', true);
    $on = ($this->price)(($this->ctx)($lines, 0, 6000, 2000));
    expect($on->taxMinor)->toBe(1500 + 900 + 300)->and(array_column($on->extraTaxLines, 'kind'))->toBe(['shipping', 'cod_fee'])->and($on->reconciles())->toBeTrue();
});

it('refuses to price something that has no price', function () {
    $p = app(SaveProduct::class)->handle($this->actor, null, new ProductData(ProductType::Physical, [new TranslationInput('en', 'Unpriced')], defaultVariant: new VariantData('NOPRICE-1')));

    expect(fn () => ($this->price)(($this->ctx)([new PricingLine((int) $p->variants()->value('id'), 'standard', 1)])))->toThrow(PricingException::class);
});

it('gives the same total whether computed live or recomputed later from the stored line numbers alone', function () {
    ($this->vat)(false);
    $live = ($this->price)(($this->ctx)([new PricingLine($this->a, 'standard', 3, 500), new PricingLine($this->b, 'standard', 2)], 1234, 6000, 2000));

    // what an order would store per line: unit price, quantity, line discount, tax rate. No catalog, no price list, no settings.
    $snapshot = array_map(fn ($l) => ['variantId' => $l->variantId, 'quantity' => $l->quantity, 'unitMinor' => $l->unitPriceMinor, 'lineDiscountMinor' => $l->lineDiscountMinor, 'taxRateBp' => $l->taxRateBp], $live->lines);
    DB::table('prices')->update(['amount_minor' => 1]); // the catalog changes afterwards
    $again = (new BreakdownCalculator)->calculate($snapshot, false, $live->orderDiscountMinor, $live->shippingMinor, $live->codFeeMinor);

    expect($again)->toEqual($live);
});

it('reads everything in a fixed number of queries', function () {
    $ids = array_map(fn ($i) => ($this->variant)("Q-{$i}", 1000 + $i), range(1, 15));
    ($this->vat)(false);

    DB::enableQueryLog();
    ($this->price)(($this->ctx)(array_map(fn ($id) => new PricingLine($id, 'standard', 2), $ids), 500, 6000));
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThanOrEqual(10);
});
