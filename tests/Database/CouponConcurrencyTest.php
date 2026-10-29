<?php

declare(strict_types=1);

use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * Coupon limits under REAL concurrency (Phase 5 gate): each probe is its own PHP process with its own MariaDB
 * connection, all released at the same instant.
 */

beforeEach(function () {
    $this->seed(LanguageSeeder::class);
    fakeOrders([...range(1, 12), 77]);
    $this->makeCoupon = fn (string $code, array $over = []) => DB::table('coupons')->insertGetId($over + ['public_id' => strtolower((string) Str::ulid()), 'code' => $code, 'type' => 'fixed', 'value' => 100, 'scope' => 'all', 'is_active' => true, 'min_spend_minor' => 0, 'used_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
});

/**
 * @param  list<list<string>>  $argLists
 * @return list<array<string, mixed>>
 */
function runCouponProbes(array $argLists): array
{
    $startAt = microtime(true) + 4.0 + count($argLists) * 0.15;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (array $args) use ($env, $startAt) {
        $p = new Process([PHP_BINARY, 'artisan', 'pricing:coupon-probe', ...$args, '--start-at='.$startAt], base_path(), $env);
        $p->setTimeout(120)->start();

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

function couponOutcomes(array $results): array
{
    $counts = array_count_values(array_map(fn (array $r) => $r['ok'] ? 'ok' : (string) $r['reason'], $results));
    ksort($counts);

    return $counts;
}

it('lets exactly one of ten simultaneous orders use the last use of a coupon', function () {
    $id = ($this->makeCoupon)('LASTONE', ['usage_limit' => 1]);

    $results = runCouponProbes(array_map(fn ($i) => ['LASTONE', "--order={$i}", "--redeemer=p:buyer{$i}"], range(1, 10)));

    expect(couponOutcomes($results))->toBe(['exhausted' => 9, 'ok' => 1])
        ->and((int) DB::table('coupons')->where('id', $id)->value('used_count'))->toBe(1)
        ->and(DB::table('coupon_redemptions')->where('coupon_id', $id)->count())->toBe(1);
});

it('never lets a limit of five be passed by twelve simultaneous orders', function () {
    $id = ($this->makeCoupon)('FIVE', ['usage_limit' => 5]);

    $results = runCouponProbes(array_map(fn ($i) => ['FIVE', "--order={$i}", "--redeemer=p:buyer{$i}"], range(1, 12)));

    expect(couponOutcomes($results))->toBe(['exhausted' => 7, 'ok' => 5])
        ->and((int) DB::table('coupons')->where('id', $id)->value('used_count'))->toBe(5)
        ->and(DB::table('coupon_redemptions')->where('coupon_id', $id)->count())->toBe(5);
});

it('lets one customer use a once-per-customer coupon only once even from many tabs at the same instant', function () {
    $id = ($this->makeCoupon)('ONEEACH', ['per_customer_limit' => 1]);

    $results = runCouponProbes(array_map(fn ($i) => ['ONEEACH', "--order={$i}", '--redeemer=u:42'], range(1, 8)));

    expect(couponOutcomes($results))->toBe(['ok' => 1, 'per_customer_limit' => 7])
        ->and(DB::table('coupon_redemptions')->where('coupon_id', $id)->count())->toBe(1)
        ->and((int) DB::table('coupons')->where('id', $id)->value('used_count'))->toBe(1);
});

it('treats the same order arriving twice at once as one use', function () {
    $id = ($this->makeCoupon)('SAMEORDER', ['usage_limit' => 5]);

    $results = runCouponProbes(array_map(fn () => ['SAMEORDER', '--order=77', '--redeemer=u:1'], range(1, 6)));

    expect(array_filter($results, fn ($r) => $r['ok']))->not->toBeEmpty()
        ->and(DB::table('coupon_redemptions')->where('coupon_id', $id)->count())->toBe(1)
        ->and((int) DB::table('coupons')->where('id', $id)->value('used_count'))->toBe(1);
});
