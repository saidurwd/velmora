<?php

declare(strict_types=1);

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Orders\Services\CheckoutPricing;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * Checkout under REAL concurrency (Phase 5 gate): every probe is its own PHP process with its own MariaDB connection,
 * all released at the same instant. The feature tests prove the logic; these prove the database enforces it.
 */

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, GeographySeeder::class, ShippingSeeder::class]);
    $actor = Actor::system('t');
    $category = app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', 'General')]);

    $this->tee = (function () use ($actor, $category): int {
        $product = app(SaveProduct::class)->handle($actor, null, new ProductData(ProductType::Physical, [new TranslationInput('en', 'Cotton Tee')], categoryIds: [$category->id], defaultVariant: new VariantData('TEE-1', trackInventory: true, weightG: 300)));
        $variantId = (int) $product->variants()->value('id');
        app(SetRegularPrice::class)->handle($actor, $variantId, Money::of(65000));
        DB::table('stock_levels')->insert(['variant_id' => $variantId, 'location_id' => (int) DB::table('stock_locations')->value('id'), 'on_hand' => 1, 'reserved' => 0, 'updated_at' => now()]);
        app(TransitionProduct::class)->publish($actor, $product);

        return $variantId;
    })();

    $this->dhaka = (int) DB::table('districts')->where('name_en', 'Dhaka')->value('id');
    $this->division = (int) DB::table('districts')->where('id', $this->dhaka)->value('division_id');
    $this->upazila = (int) DB::table('upazilas')->where('district_id', $this->dhaka)->value('id');

    /** a guest with one tee in the cart; returns [guestToken, total the page would show] */
    $this->shopper = function (string $guest, int $qty = 1): array {
        app(AddToCart::class)->handle(CartOwner::guest($guest), $this->tee, $qty);

        return [$guest, app(CheckoutPricing::class)->quote(app(CartReader::class)->view(CartOwner::guest($guest), 'en'), $this->dhaka, 'standard', 'cod', 'en')->breakdown->grandTotalMinor];
    };
});

/**
 * @param  list<list<string>>  $argLists
 * @return list<array<string, mixed>>
 */
function runPlaceProbes(array $argLists): array
{
    $startAt = microtime(true) + 5.0 + count($argLists) * 0.2;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (array $args) use ($env, $startAt) {
        $p = new Process([PHP_BINARY, 'artisan', 'orders:place-probe', ...$args, '--start-at='.$startAt], base_path(), $env);
        $p->setTimeout(180)->start();

        return $p;
    }, $argLists);

    return array_map(function (Process $p): array {
        $p->wait();
        $lines = array_values(array_filter(explode("\n", trim($p->getOutput()))));
        $decoded = json_decode((string) end($lines), true);
        expect($decoded)->toBeArray('probe produced no JSON: '.$p->getOutput().$p->getErrorOutput());

        return $decoded;
    }, $processes);
}

it('sells the last unit to exactly one of six simultaneous shoppers', function () {
    $args = [];
    foreach (range(1, 6) as $i) {
        [$guest, $total] = ($this->shopper)(str_repeat((string) $i, 40));
        $args[] = [$guest, "token-last-unit-{$i}", "--division={$this->division}", "--district={$this->dhaka}", "--upazila={$this->upazila}", "--expected={$total}"];
    }

    $results = runPlaceProbes($args);

    $won = array_values(array_filter($results, fn ($r) => $r['ok']));
    $lost = array_values(array_filter($results, fn ($r) => ! $r['ok']));
    expect($won)->toHaveCount(1)->and($lost)->toHaveCount(5)
        ->and(array_unique(array_column($lost, 'reason')))->each->toBeIn(['insufficient_stock', 'cart_problem'])
        ->and(DB::table('orders')->count())->toBe(1)->and((int) DB::table('stock_levels')->where('variant_id', $this->tee)->value('reserved'))->toBe(1)
        ->and((int) DB::table('stock_levels')->where('variant_id', $this->tee)->value('on_hand'))->toBe(1)
        ->and(DB::table('stock_reservations')->where('owner_type', 'order')->count())->toBe(1)
        ->and(DB::table('cart_items')->count())->toBe(5);   // the five who lost still have their cart
});

it('makes one order when the same checkout is submitted six times at the same instant', function () {
    DB::table('stock_levels')->where('variant_id', $this->tee)->update(['on_hand' => 20]);
    [$guest, $total] = ($this->shopper)(str_repeat('e', 40), 2);

    $results = runPlaceProbes(array_map(fn () => [$guest, 'token-double-submit', "--division={$this->division}", "--district={$this->dhaka}", "--upazila={$this->upazila}", "--expected={$total}"], range(1, 6)));

    expect(array_column($results, 'ok'))->each->toBeTrue()
        ->and(array_unique(array_column($results, 'order')))->toHaveCount(1)
        ->and(DB::table('orders')->count())->toBe(1)->and((int) DB::table('stock_levels')->where('variant_id', $this->tee)->value('reserved'))->toBe(2)
        ->and(DB::table('stock_reservations')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('event_name', 'orders.placed')->count())->toBe(1)
        ->and(DB::table('order_items')->count())->toBe(1);
});

it('lets a one-use coupon discount exactly one of five simultaneous orders and leaves no stock held by the others', function () {
    DB::table('stock_levels')->where('variant_id', $this->tee)->update(['on_hand' => 20]);
    $couponId = DB::table('coupons')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'code' => 'ONLYONE', 'type' => 'fixed', 'value' => 5000, 'scope' => 'all', 'is_active' => true, 'min_spend_minor' => 0, 'usage_limit' => 1, 'used_count' => 0, 'created_at' => now(), 'updated_at' => now()]);

    $args = [];
    foreach (range(1, 5) as $i) {
        [$guest] = ($this->shopper)(str_repeat(dechex($i + 9), 40));
        DB::table('cart_coupons')->insert(['cart_id' => DB::table('carts')->where('guest_token_hash', hash('sha256', $guest))->value('id'), 'code' => 'ONLYONE', 'created_at' => now(), 'updated_at' => now()]);
        $total = app(CheckoutPricing::class)->quote(app(CartReader::class)->view(CartOwner::guest($guest), 'en'), $this->dhaka, 'standard', 'cod', 'en')->breakdown->grandTotalMinor;
        $args[] = [$guest, "token-coupon-{$i}", "--division={$this->division}", "--district={$this->dhaka}", "--upazila={$this->upazila}", "--expected={$total}"];
    }

    $results = runPlaceProbes($args);

    $won = array_filter($results, fn ($r) => $r['ok']);
    expect($won)->toHaveCount(1)->and(array_unique(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'reason')))->each->toBeIn(['coupon_failed', 'totals_changed'])
        ->and((int) DB::table('coupons')->where('id', $couponId)->value('used_count'))->toBe(1)
        ->and(DB::table('coupon_redemptions')->count())->toBe(1)->and(DB::table('orders')->count())->toBe(1)
        ->and((int) DB::table('orders')->value('discount_minor'))->toBe(5000)
        ->and((int) DB::table('stock_levels')->where('variant_id', $this->tee)->value('reserved'))->toBe(1);   // only the winner holds stock
});

it('lets at most three of eight simultaneous cash orders from one phone through, and never four', function () {
    DB::table('stock_levels')->where('variant_id', $this->tee)->update(['on_hand' => 50]);

    $args = [];
    foreach (range(1, 8) as $i) {
        [$guest, $total] = ($this->shopper)(str_repeat(dechex($i + 9), 40));
        $args[] = [$guest, "token-open-cod-{$i}", "--division={$this->division}", "--district={$this->dhaka}", "--upazila={$this->upazila}", "--expected={$total}", '--phone=01755500000'];
    }

    $results = runPlaceProbes($args);

    $won = array_filter($results, fn ($r) => $r['ok']);
    $lost = array_values(array_filter($results, fn ($r) => ! $r['ok']));
    expect($won)->toHaveCount(3)->and(array_unique(array_column($lost, 'reason')))->each->toBeIn(['too_many_open_cod'])
        ->and(DB::table('orders')->where('contact_phone', '+8801755500000')->count())->toBe(3)
        ->and((int) DB::table('stock_levels')->where('variant_id', $this->tee)->value('reserved'))->toBe(3)
        ->and(DB::table('cart_items')->count())->toBe(5);   // the five refused keep their carts
});

it('does not let one phone\'s lock slow or block other phones', function () {
    DB::table('stock_levels')->where('variant_id', $this->tee)->update(['on_hand' => 50]);

    $args = [];
    foreach (range(1, 6) as $i) {
        [$guest, $total] = ($this->shopper)(str_repeat(dechex($i + 9), 40));
        $args[] = [$guest, "token-many-phones-{$i}", "--division={$this->division}", "--district={$this->dhaka}", "--upazila={$this->upazila}", "--expected={$total}", '--phone=0176660000'.$i];
    }

    $results = runPlaceProbes($args);

    expect(array_column($results, 'ok'))->each->toBeTrue()->and(DB::table('orders')->count())->toBe(6);
});
