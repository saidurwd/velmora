<?php

declare(strict_types=1);

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Services\GuestCartToken;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\DTOs\NewOrderAddress;
use App\Modules\Orders\DTOs\NewOrderLine;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Pricing\Services\BreakdownCalculator;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Phase 5 gate: what the exit criteria promise beyond the behaviour tested elsewhere.
 *  - an order's totals can be rebuilt from its own stored rows, with no catalog or price read
 *  - the checkout submit is fast (≤ 800 ms server time) and uses a bounded number of queries
 */

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, GeographySeeder::class, ShippingSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $actor = Actor::system('t');
    $category = app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', 'General')]);

    $this->make = function (string $sku, int $taka) use ($actor, $category): int {
        $product = app(SaveProduct::class)->handle($actor, null, new ProductData(ProductType::Physical, [new TranslationInput('en', "Item {$sku}"), new TranslationInput('bn', "পণ্য {$sku}")], categoryIds: [$category->id], defaultVariant: new VariantData($sku, trackInventory: true, weightG: 300)));
        $variantId = (int) $product->variants()->value('id');
        app(SetRegularPrice::class)->handle($actor, $variantId, Money::of($taka * 100));
        DB::table('stock_levels')->insert(['variant_id' => $variantId, 'location_id' => (int) DB::table('stock_locations')->value('id'), 'on_hand' => 100000, 'reserved' => 0, 'updated_at' => now()]);
        app(TransitionProduct::class)->publish($actor, $product);

        return $variantId;
    };
    $this->variants = [($this->make)('A-1', 650), ($this->make)('B-1', 350), ($this->make)('C-1', 1200)];
    $this->dhaka = (int) DB::table('districts')->where('name_en', 'Dhaka')->value('id');
    $this->division = (int) DB::table('districts')->where('id', $this->dhaka)->value('division_id');
    $this->upazila = (int) DB::table('upazilas')->where('district_id', $this->dhaka)->value('id');
});

it('rebuilds every order\'s totals from its own stored rows alone, for hundreds of random orders', function () {
    mt_srand(20261029);
    $calc = new BreakdownCalculator;
    $address = new NewOrderAddress('shipping', 'Rahim', '+8801712345678', $this->division, $this->dhaka, $this->upazila, ['division' => ['en' => 'Dhaka'], 'district' => ['en' => 'Dhaka'], 'upazila' => ['en' => 'Savar']], 'House 1');
    $create = app(CreateOrder::class);
    $checked = 0;

    foreach (range(1, 250) as $i) {
        $inclusive = (bool) mt_rand(0, 1);
        $rate = [0, 0, 500, 1500][mt_rand(0, 3)];
        $lines = [];
        foreach (array_slice($this->variants, 0, mt_rand(1, 3)) as $variantId) {
            $unit = mt_rand(1, 600_000);
            $qty = mt_rand(1, 6);
            $lines[] = ['variantId' => $variantId, 'quantity' => $qty, 'unitMinor' => $unit, 'lineDiscountMinor' => mt_rand(0, 1) === 1 ? mt_rand(0, (int) ($unit * $qty / 3)) : 0, 'taxRateBp' => $rate];
        }
        $breakdown = $calc->calculate($lines, $inclusive, mt_rand(0, 1) === 1 ? mt_rand(0, 150_000) : 0, mt_rand(0, 25_000), mt_rand(0, 1) === 1 ? mt_rand(0, 8_000) : 0, mt_rand(0, 2) === 0 ? $rate : 0, mt_rand(0, 3) === 0 ? $rate : 0);

        $order = $create->handle(new NewOrder(
            Actor::guest('t'), null, 'Rahim', '+8801712345678', null, OrderChannel::Web, 'en', 'cod', 'standard', $breakdown,
            array_map(fn ($l) => new NewOrderLine($l['variantId'], 'physical', ['en' => 'x'], 'SKU-'.$l['variantId']), $lines), [$address],
        ));

        // ---- from here on, only the order's own rows are read: no catalog, no price list, no settings ----
        $items = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get();
        $row = DB::table('orders')->where('id', $order->id)->first();
        $tax = DB::table('order_tax_lines')->where('order_id', $order->id)->pluck('rate_bp', 'kind');
        $again = $calc->calculate(
            $items->map(fn ($it) => ['variantId' => (int) $it->variant_id, 'quantity' => (int) $it->quantity, 'unitMinor' => (int) $it->unit_price_minor, 'lineDiscountMinor' => (int) $it->discount_minor, 'taxRateBp' => (int) $it->tax_rate_bp])->all(),
            (bool) $row->tax_inclusive, 0, (int) $row->shipping_minor, (int) $row->cod_fee_minor, (int) ($tax['shipping'] ?? 0), (int) ($tax['cod_fee'] ?? 0),
        );

        expect($again->grandTotalMinor)->toBe((int) $row->grand_total_minor)->and($again->taxMinor)->toBe((int) $row->tax_minor)->and($again->subtotalMinor)->toBe((int) $row->subtotal_minor)
            ->and($again->discountMinor())->toBe((int) $row->discount_minor)->and($again->reconciles())->toBeTrue();
        foreach ($again->lines as $k => $line) {
            expect($line->totalMinor)->toBe((int) $items[$k]->line_total_minor)->and($line->taxMinor)->toBe((int) $items[$k]->tax_minor);
        }
        $checked++;
    }

    expect($checked)->toBe(250);
});

it('keeps an order\'s numbers when the catalog, prices and settings change afterwards', function () {
    $breakdown = (new BreakdownCalculator)->calculate([['variantId' => $this->variants[0], 'quantity' => 2, 'unitMinor' => 65000, 'lineDiscountMinor' => 0, 'taxRateBp' => 1500]], false, 0, 6000, 0);
    $order = app(CreateOrder::class)->handle(new NewOrder(
        Actor::guest('t'), null, 'R', '+8801712345678', null, OrderChannel::Web, 'en', 'cod', 'standard', $breakdown,
        [new NewOrderLine($this->variants[0], 'physical', ['en' => 'Item A-1'], 'A-1')],
        [new NewOrderAddress('shipping', 'R', '+8801712345678', $this->division, $this->dhaka, $this->upazila, ['division' => ['en' => 'Dhaka']], 'House 1')],
    ));
    $before = DB::table('orders')->where('id', $order->id)->first(['subtotal_minor', 'tax_minor', 'grand_total_minor']);

    app(SetRegularPrice::class)->handle(Actor::system('t'), $this->variants[0], Money::of(1));
    DB::table('product_translations')->update(['name' => 'Renamed']);
    DB::table('tax_rates')->update(['rate_bp' => 0]);
    app(Settings::class)->set('tax.prices_include_tax', true);

    expect(DB::table('orders')->where('id', $order->id)->first(['subtotal_minor', 'tax_minor', 'grand_total_minor']))->toEqual($before)
        ->and((int) DB::table('order_items')->where('order_id', $order->id)->value('unit_price_minor'))->toBe(65000);
});

describe('the submit is fast', function () {
    beforeEach(function () {
        $this->flow = function (int $n): array {
            $token = str_repeat(dechex($n % 15 + 1), 40);
            $phone = '0171'.str_pad((string) (1000000 + $n), 7, '0', STR_PAD_LEFT);
            foreach ($this->variants as $v) {
                app(AddToCart::class)->handle(CartOwner::guest($token), $v, 2);
            }
            $as = fn () => $this->withCookie(GuestCartToken::COOKIE, $token);
            $as()->post('/en/checkout', ['recipient' => 'Rahim Uddin', 'phone' => $phone, 'division_id' => $this->division, 'district_id' => $this->dhaka, 'upazila_id' => $this->upazila, 'address_line' => 'House 5, Road 2'])->assertRedirect('/en/checkout/review');
            $html = $as()->get('/en/checkout/review')->getContent();
            preg_match('/name="place_token" value="([^"]+)"/', $html, $t);
            preg_match('/name="expected_total" value="(\d+)"/', $html, $e);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $start = hrtime(true);
            $as()->post('/en/checkout/place', ['place_token' => $t[1], 'expected_total' => $e[1], 'shipping' => 'standard', 'payment' => 'cod'])->assertRedirect();
            $ms = (hrtime(true) - $start) / 1e6;
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$ms, $queries];
        };
    });

    it('places a three-line cash order in at most 800 ms (median of seven) with a bounded number of queries', function () {
        ($this->flow)(0);   // warm-up: opcache, route and view caches, translations
        $runs = [];
        foreach (range(1, 7) as $n) {
            $runs[] = ($this->flow)($n);
        }
        $ms = array_column($runs, 0);
        sort($ms);
        $queries = array_column($runs, 1);
        fwrite(STDERR, sprintf("\nSUBMIT median %.0f ms, max %.0f ms, queries %d–%d\n", $ms[3], max($ms), min($queries), max($queries)));

        expect($ms[3])->toBeLessThanOrEqual(800.0)->and(max($queries))->toBeLessThanOrEqual(120)->and(max($queries) - min($queries))->toBeLessThanOrEqual(2);
    });

    it('does not slow down as the shop grows: the same submit with thousands of earlier orders', function () {
        $now = now();
        $rows = [];
        foreach (range(1, 3000) as $i) {
            $rows[] = ['public_id' => strtolower((string) Str::ulid()), 'order_number' => sprintf('VM-OLD-%06d', $i), 'contact_name' => 'Old', 'contact_phone' => '+88018'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'locale' => 'en', 'subtotal_minor' => 0, 'discount_minor' => 0, 'shipping_minor' => 0,
                'cod_fee_minor' => 0, 'tax_minor' => 0, 'grand_total_minor' => 0, 'tax_inclusive' => false, 'payment_method_code' => 'cod', 'status' => 'completed', 'placed_at' => $now->copy()->subDays($i % 90), 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('orders')->insert($chunk);
        }

        ($this->flow)(0);
        $ms = array_map(fn ($n) => ($this->flow)($n)[0], range(1, 5));
        sort($ms);
        fwrite(STDERR, sprintf("\nSUBMIT with 3,000 orders median %.0f ms\n", $ms[2]));

        expect($ms[2])->toBeLessThanOrEqual(800.0);
    });
});
