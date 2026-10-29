<?php

declare(strict_types=1);

use App\Modules\Cart\DTOs\CartLineView;
use App\Modules\Cart\DTOs\CartView;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Shipping\Contracts\ShippingQuoter;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
use App\Modules\Shipping\DTOs\ShippingRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, GeographySeeder::class, ShippingSeeder::class]);
    $this->dhaka = (int) DB::table('districts')->where('name_en', 'Dhaka')->value('id');
    $this->sylhet = (int) DB::table('districts')->where('name_en', 'Sylhet')->value('id');
    $this->quote = fn (ShippingRequest $r, string $locale = 'en') => app(ShippingQuoter::class)->quote($r, $locale);
    $this->req = fn (?int $district, int $merch = 100000, int $weight = 500, bool $ship = true, bool $coupon = false) => new ShippingRequest($district, $merch, $weight, $ship, $coupon);
    $this->method = fn (string $code = 'standard') => (int) DB::table('shipping_methods')->where('code', $code)->value('id');
    $this->zone = fn (bool $fallback) => (int) DB::table('shipping_zones')->where('is_fallback', $fallback)->value('id');
});

describe('where it is going', function () {
    it('charges Dhaka one rate and the rest of the country another', function () {
        $dhaka = ($this->quote)(($this->req)($this->dhaka));
        $sylhet = ($this->quote)(($this->req)($this->sylhet));

        expect($dhaka->zoneName)->toBe('Dhaka')->and($dhaka->options[0]->price->amountMinor)->toBe(6000)
            ->and($sylhet->zoneName)->toBe('Rest of Bangladesh')->and($sylhet->options[0]->price->amountMinor)->toBe(12000);
    });

    it('uses the fallback zone when the district is not known yet', function () {
        $q = ($this->quote)(($this->req)(null));

        expect($q->zoneName)->toBe('Rest of Bangladesh')->and($q->options[0]->price->amountMinor)->toBe(12000);
    });

    it('answers in Bangla with Bangla names and delivery times', function () {
        $q = ($this->quote)(($this->req)($this->dhaka), 'bn');

        expect($q->zoneName)->toBe('ঢাকা')->and($q->options[0]->name)->toBe('সাধারণ ডেলিভারি')->and($q->options[0]->deliveryEstimate)->toContain('কর্মদিবস');
    });

    it('cannot put one district in two zones, or have two fallback zones', function () {
        $other = DB::table('shipping_zones')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'is_fallback' => false, 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => DB::table('shipping_zone_districts')->insert(['zone_id' => $other, 'district_id' => $this->dhaka]))->toThrow(QueryException::class);
        expect(fn () => DB::table('shipping_zones')->insert(['public_id' => strtolower((string) Str::ulid()), 'is_fallback' => true, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
    });

    it('offers no delivery when there is no fallback zone and the district is unclaimed', function () {
        DB::table('shipping_zones')->where('is_fallback', true)->update(['is_active' => false]);
        $q = ($this->quote)(($this->req)($this->sylhet));

        expect($q->required)->toBeTrue()->and($q->options)->toBe([])->and($q->canDeliver())->toBeFalse();
    });
});

describe('what is being sent', function () {
    it('skips shipping entirely for digital and service carts', function () {
        $q = ($this->quote)(($this->req)($this->dhaka, ship: false));

        expect($q->required)->toBeFalse()->and($q->options)->toBe([])->and($q->canDeliver())->toBeTrue();
    });

    it('prices by weight band, picking the smallest band that fits', function () {
        $zone = ($this->zone)(true);
        $method = ($this->method)();
        DB::table('shipping_rates')->where('zone_id', $zone)->delete();
        foreach ([[1000, 10000], [5000, 15000], [null, 30000]] as [$max, $price]) {
            DB::table('shipping_rates')->insert(['zone_id' => $zone, 'method_id' => $method, 'max_weight_g' => $max, 'price_minor' => $price, 'created_at' => now(), 'updated_at' => now()]);
        }
        $price = fn (int $g) => ($this->quote)(($this->req)($this->sylhet, weight: $g))->options[0]->price->amountMinor;

        expect($price(0))->toBe(10000)->and($price(1000))->toBe(10000)->and($price(1001))->toBe(15000)->and($price(5000))->toBe(15000)->and($price(5001))->toBe(30000)->and($price(99999))->toBe(30000);
    });

    it('offers no method that cannot carry the weight', function () {
        $zone = ($this->zone)(true);
        DB::table('shipping_rates')->where('zone_id', $zone)->update(['max_weight_g' => 2000]);

        expect(($this->quote)(($this->req)($this->sylhet, weight: 2000))->options)->toHaveCount(1)->and(($this->quote)(($this->req)($this->sylhet, weight: 2001))->options)->toBe([]);
    });

    it('allows only one "and above" band per zone and method, and one price per band', function () {
        $zone = ($this->zone)(true);
        $method = ($this->method)();
        $row = ['zone_id' => $zone, 'method_id' => $method, 'price_minor' => 1, 'created_at' => now(), 'updated_at' => now()];

        expect(fn () => DB::table('shipping_rates')->insert($row + ['max_weight_g' => null]))->toThrow(QueryException::class);
        DB::table('shipping_rates')->insert($row + ['max_weight_g' => 500]);
        expect(fn () => DB::table('shipping_rates')->insert($row + ['max_weight_g' => 500]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('shipping_rates')->insert(['price_minor' => -1, 'max_weight_g' => 900] + $row))->toThrow(QueryException::class);
    });
});

describe('free delivery', function () {
    it('is free exactly from the threshold, not a paisa before', function () {
        $quote = fn (int $merch) => ($this->quote)(($this->req)($this->dhaka, $merch))->options[0];

        expect($quote(299999)->price->amountMinor)->toBe(6000)->and($quote(299999)->isFree)->toBeFalse()
            ->and($quote(300000)->price->amountMinor)->toBe(0)->and($quote(300000)->isFree)->toBeTrue()->and($quote(500000)->isFree)->toBeTrue();
    });

    it('can be switched off by clearing the threshold, and forced by a free-shipping coupon', function () {
        DB::table('shipping_methods')->update(['free_over_minor' => null]);

        expect(($this->quote)(($this->req)($this->dhaka, 9_000_000))->options[0]->isFree)->toBeFalse()
            ->and(($this->quote)(($this->req)($this->dhaka, 100, coupon: true))->options[0]->isFree)->toBeTrue()
            ->and(($this->quote)(($this->req)($this->dhaka, 100, coupon: true))->options[0]->price->amountMinor)->toBe(0);
    });

    it('does not touch the cash-on-delivery fee', function () {
        DB::table('shipping_methods')->update(['cod_fee_minor' => 4000]);
        $o = ($this->quote)(($this->req)($this->dhaka, 500000))->options[0];

        expect($o->isFree)->toBeTrue()->and($o->codFee->amountMinor)->toBe(4000);
    });
});

describe('cash on delivery', function () {
    it('needs both the zone and the method to allow it', function () {
        $cod = fn (int $d) => ($this->quote)(($this->req)($d))->options[0]->codAvailable;
        expect($cod($this->dhaka))->toBeTrue()->and($cod($this->sylhet))->toBeTrue();

        DB::table('shipping_zones')->where('is_fallback', true)->update(['cod_available' => false]);
        expect($cod($this->dhaka))->toBeTrue()->and($cod($this->sylhet))->toBeFalse();

        DB::table('shipping_zones')->update(['cod_available' => true]);
        DB::table('shipping_methods')->update(['cod_available' => false]);
        expect($cod($this->dhaka))->toBeFalse();
    });

    it('adds the flat fee only where cash on delivery is possible', function () {
        DB::table('shipping_methods')->update(['cod_fee_minor' => 5000]);
        DB::table('shipping_zones')->where('is_fallback', true)->update(['cod_available' => false]);

        expect(($this->quote)(($this->req)($this->dhaka))->options[0]->codFee->amountMinor)->toBe(5000)
            ->and(($this->quote)(($this->req)($this->sylhet))->options[0]->codFee->amountMinor)->toBe(0);
    });
});

describe('methods', function () {
    it('lists active methods in order with their own prices, skipping inactive ones', function () {
        $now = now();
        $express = DB::table('shipping_methods')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'code' => 'express', 'cod_available' => false, 'cod_fee_minor' => 0, 'free_over_minor' => null, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('shipping_method_translations')->insert([['method_id' => $express, 'locale' => 'en', 'name' => 'Express', 'delivery_estimate' => 'Next day']]);
        DB::table('shipping_rates')->insert(['zone_id' => ($this->zone)(false), 'method_id' => $express, 'max_weight_g' => null, 'price_minor' => 20000, 'created_at' => $now, 'updated_at' => $now]);

        $q = ($this->quote)(($this->req)($this->dhaka, 500000));
        expect(array_map(fn ($o) => [$o->methodCode, $o->price->amountMinor, $o->codAvailable], $q->options))->toBe([['standard', 0, true], ['express', 20000, false]]);

        DB::table('shipping_methods')->where('code', 'express')->update(['is_active' => false]);
        expect(count(($this->quote)(($this->req)($this->dhaka))->options))->toBe(1);
    });

    it('falls back to the default language when a translation is missing, and to the code when there is none', function () {
        DB::table('shipping_method_translations')->where('locale', 'bn')->delete();
        expect(($this->quote)(($this->req)($this->dhaka), 'bn')->options[0]->name)->toBe('Standard delivery');

        DB::table('shipping_method_translations')->delete();
        expect(($this->quote)(($this->req)($this->dhaka), 'bn')->options[0]->name)->toBe('standard');
    });
});

it('builds a request from a cart: physical weight only, unavailable lines ignored', function () {
    $line = fn (string $type, int $qty, int $weight, ?string $problem = null) => new CartLineView('p', 1, 'S', 'N', null, 's', null, $type, $qty, 1, null, 1, Money::of(10000), Money::of(10000 * $qty), null, $problem, null, $weight);
    $cart = new CartView('c', [$line('physical', 2, 300), $line('digital', 1, 0), $line('physical', 5, 1000, 'unavailable')], Money::of(30000), 8);

    $r = ShippingRequest::fromCart($cart, 5);
    expect([$r->districtId, $r->merchandiseMinor, $r->totalWeightG, $r->requiresShipping])->toBe([5, 30000, 600, true]);

    $digital = ShippingRequest::fromCart(new CartView('c', [$line('digital', 1, 0)], Money::of(10000), 1), null);
    expect($digital->requiresShipping)->toBeFalse();
});

it('quotes in a fixed number of queries', function () {
    DB::enableQueryLog();
    ($this->quote)(($this->req)($this->dhaka));
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThanOrEqual(5);
});

it('seeds placeholder rates only on a fresh install and never overwrites the owner\'s numbers', function () {
    DB::table('shipping_rates')->where('zone_id', ($this->zone)(false))->update(['price_minor' => 9900]);

    $this->seed(ShippingSeeder::class);

    expect((int) DB::table('shipping_rates')->where('zone_id', ($this->zone)(false))->value('price_minor'))->toBe(9900)->and(DB::table('shipping_zones')->count())->toBe(2);
});
