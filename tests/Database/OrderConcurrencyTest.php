<?php

declare(strict_types=1);

use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Order state under REAL concurrency (Phase 5 gate): every probe is its own PHP process with its own MariaDB connection,
 * all released at the same instant.
 */

beforeEach(function () {
    $this->seed(LanguageSeeder::class);
    fakeOrders([1]);
    $this->setState = fn (array $columns) => DB::table('orders')->where('id', 1)->update($columns);
});

/**
 * @param  list<list<string>>  $argLists
 * @return list<array<string, mixed>>
 */
function runOrderProbes(array $argLists): array
{
    $startAt = microtime(true) + 4.0 + count($argLists) * 0.15;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (array $args) use ($env, $startAt) {
        $p = new Process([PHP_BINARY, 'artisan', 'orders:probe', ...$args, '--start-at='.$startAt], base_path(), $env);
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

it('lets exactly one of ten simultaneous confirmations win, with one history row', function () {
    $results = runOrderProbes(array_map(fn () => ['status', '--order=1', '--to=confirmed'], range(1, 10)));

    $counts = array_count_values(array_map(fn ($r) => $r['ok'] ? 'ok' : (string) $r['reason'], $results));
    ksort($counts);
    expect($counts)->toBe(['already_in_state' => 9, 'ok' => 1])
        ->and(DB::table('orders')->where('id', 1)->value('status'))->toBe('confirmed')
        ->and(DB::table('order_status_history')->where('order_id', 1)->where('axis', 'status')->count())->toBe(1);
});

it('never ends up both cancelled and shipped when cancel and ship happen at the same instant', function () {
    $outcomes = [];
    foreach (range(1, 3) as $round) {
        DB::table('orders')->where('id', 1)->update(['status' => 'confirmed', 'fulfillment_status' => 'unfulfilled', 'cancelled_at' => null, 'cancel_reason' => null]);
        // confirmed, unpaid, unshipped: cancelling and shipping are both legal, but only one of them may happen
        $results = runOrderProbes([['status', '--order=1', '--to=cancelled', '--reason=changed my mind'], ['fulfillment', '--order=1', '--to=fulfilled']]);

        $row = DB::table('orders')->where('id', 1)->first(['status', 'fulfillment_status', 'cancelled_at']);
        $cancelled = $row->status === 'cancelled';
        $shipped = $row->fulfillment_status !== 'unfulfilled';
        expect($cancelled && $shipped)->toBeFalse()->and($cancelled || $shipped)->toBeTrue()->and(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1);
        $outcomes[] = $cancelled ? 'cancelled' : 'shipped';
    }
    expect($outcomes)->not->toBeEmpty();
});

it('draws a different order number for each of twelve simultaneous orders', function () {
    $results = runOrderProbes(array_map(fn () => ['number'], range(1, 12)));
    $numbers = array_column($results, 'number');

    expect($numbers)->toHaveCount(12)->and(array_unique($numbers))->toHaveCount(12);
    foreach ($numbers as $n) {
        expect($n)->toMatch('/^VM-\d{4}-\d{6}$/');
    }
});
