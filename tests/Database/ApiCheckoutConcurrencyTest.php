<?php

declare(strict_types=1);

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\DigitalProducts\Database\Seeders\RefundPolicySeeder;
use App\Modules\Orders\Models\Order;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Checkout from the app under REAL concurrency (Phase 11): every probe is its own PHP process with its own MariaDB connection, each sending
 * POST /api/v1/checkout/orders through the whole HTTP stack, all released at the same instant. Rules proved:
 *   - a retry storm (the same cart and the same Idempotency-Key, sent many times at once) makes exactly ONE order;
 *   - the last unit of stock goes to exactly one of many buyers, and stock never goes below zero;
 *   - two guests who happen to send the same key still each get their own order, never each other's.
 */

beforeEach(function () {
    shopWorld($this);
    $this->seed([GeographySeeder::class, ShippingSeeder::class, RefundPolicySeeder::class]);
    $dhaka = (int) DB::table('districts')->where('name_en', 'Dhaka')->value('id');
    $this->body = fn (int $total) => json_encode([
        'address' => ['recipient' => 'Rahim', 'phone' => '01712345678', 'division_id' => (int) DB::table('districts')->where('id', $dhaka)->value('division_id'), 'district_id' => $dhaka, 'upazila_id' => (int) DB::table('upazilas')->where('district_id', $dhaka)->value('id'), 'address_line' => 'House 5', 'postal_code' => '1205'],
        'shipping_method' => 'standard', 'payment_method' => 'cod', 'expected_total' => $total,
    ], JSON_THROW_ON_ERROR);
    $this->cartOf = function (int $variantId, int $qty = 1): string {
        $token = bin2hex(random_bytes(20));
        app(AddToCart::class)->handle(CartOwner::guest($token), $variantId, $qty);

        return $token;
    };
});

/**
 * @param  list<array{0: string, 1: string, 2: string}>  $jobs  [cart token, idempotency key, body]
 * @return list<array{status: int, code: string|null, order: string|null, replayed: bool}>
 */
function raceCheckout(array $jobs): array
{
    $startAt = microtime(true) + 4.0 + count($jobs) * 0.25;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (array $job) use ($env, $startAt) {
        $p = new Process([PHP_BINARY, 'artisan', 'orders:api-checkout-probe', $job[0], $job[1], $job[2], '--start-at='.$startAt], base_path(), $env);
        $p->setTimeout(240)->start();

        return $p;
    }, $jobs);

    return array_map(function (Process $p): array {
        $p->wait();
        $lines = array_values(array_filter(explode("\n", trim($p->getOutput()))));
        $decoded = json_decode((string) end($lines), true);
        expect($decoded)->toBeArray('probe produced no JSON: '.$p->getOutput().$p->getErrorOutput());

        return $decoded;
    }, $processes);
}

it('makes exactly one order when the same checkout is sent six times at the same instant', function () {
    foreach (range(1, 2) as $round) {
        $token = ($this->cartOf)($this->tee['variantId'], 2);
        $key = 'storm-'.$round.'-'.bin2hex(random_bytes(6));
        $before = Order::count();

        $results = raceCheckout(array_fill(0, 6, [$token, $key, ($this->body)(136000)]));

        $orders = array_unique(array_filter(array_column($results, 'order')));
        expect(Order::count() - $before)->toBe(1, 'round '.$round.': '.json_encode($results))
            ->and($orders)->toHaveCount(1)
            ->and(array_column($results, 'status'))->each->toBeIn([201, 409])
            ->and(in_array(201, array_column($results, 'status'), true))->toBeTrue();
        expect((int) DB::table('stock_reservations')->where('owner_type', 'order')->where('owner_ref', array_values($orders)[0])->sum('quantity'))->toBe(2);
    }
});

it('gives the last unit to exactly one of six buyers and never takes stock below zero', function () {
    $carts = array_map(fn () => ($this->cartOf)($this->tee['variantId'], 1), range(1, 6));
    ($this->setStock)($this->tee, 1);

    $results = raceCheckout(array_map(fn (string $t) => [$t, 'last-'.bin2hex(random_bytes(6)), ($this->body)(71000)], $carts));

    $won = array_filter($results, fn (array $r) => $r['status'] === 201);
    expect($won)->toHaveCount(1, json_encode($results))->and(Order::count())->toBe(1)
        ->and(array_column(array_diff_key($results, $won), 'status'))->each->toBeIn([409, 422]);
    $level = DB::table('stock_levels')->where('variant_id', $this->tee['variantId'])->first();
    expect((int) $level->on_hand - (int) $level->reserved)->toBeGreaterThanOrEqual(0)->and((int) $level->reserved)->toBe(1);
});

it('keeps two guests apart even when they send the very same key', function () {
    $a = ($this->cartOf)($this->tee['variantId'], 2);
    $b = ($this->cartOf)($this->tee['variantId'], 2);

    $results = raceCheckout([[$a, 'shared-key-0001', ($this->body)(136000)], [$b, 'shared-key-0001', ($this->body)(136000)]]);

    expect(array_column($results, 'status'))->each->toBeIn([201, 409]);
    $made = array_filter(array_column($results, 'order'));
    expect(count(array_unique($made)))->toBe(count($made))->and(Order::count())->toBe(count($made));
});
