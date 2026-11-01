<?php

declare(strict_types=1);

use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Inventory\Services\StockLedgerVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * Transfers and stock-takes under REAL concurrency (Phase 7 gate): every probe is its own PHP process with its own MariaDB connection, all released at the
 * same instant. Rules proved: units promised to an order never leave in a transfer, a transfer is received once, a count is approved once and corrects stock once.
 */

beforeEach(function () {
    shippingWorld($this);   // two products with 50 each in "main"
    DB::table('stock_locations')->insert(['public_id' => strtolower((string) Str::ulid()), 'code' => 'shop', 'name' => 'Dhanmondi shop', 'type' => 'store', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->people = [catalogStaff([])->id, catalogStaff([])->id, catalogStaff([])->id];
    $this->level = fn (int $variantId, string $code = 'main') => DB::table('stock_levels')->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')->where('variant_id', $variantId)->where('stock_locations.code', $code)->first(['on_hand', 'reserved']);
});

/**
 * @param  list<array{0: string, 1: list<string>}>  $jobs  [artisan command, arguments]
 * @return list<array<string, mixed>>
 */
function runStockOpsProbes(array $jobs): array
{
    $startAt = microtime(true) + 4.0 + count($jobs) * 0.2;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (array $job) use ($env, $startAt) {
        [$command, $args] = $job;
        $p = new Process([PHP_BINARY, 'artisan', $command, ...$args, '--start-at='.$startAt], base_path(), $env);
        $p->setTimeout(180)->start();

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

it('moves only free units when transfers and an order\'s hold race for the same stock', function () {
    // 50 tees on the shelf, 40 promised to orders: only 10 are free to move
    app(StockReservations::class)->holdForOrder([$this->tee => 40], 'HOLD1');
    $before = ($this->level)($this->tee);

    $results = runStockOpsProbes([
        ['inventory:ops-probe', ['send', "--lines={$this->tee}:6"]],
        ['inventory:ops-probe', ['send', "--lines={$this->tee}:6"]],
        ['inventory:ops-probe', ['send', "--lines={$this->tee}:6"]],
        ['inventory:probe', ['reserve', "--variant={$this->tee}", '--qty=6', '--owner=ORDER1']],
    ]);

    $after = ($this->level)($this->tee);
    $winners = count(array_filter($results, fn ($r) => $r['ok'] ?? false));
    expect($before->on_hand - $before->reserved)->toBe(10)->and($winners)->toBe(1, 'only 10 were free, so exactly one taker of 6 wins')
        ->and($after->on_hand)->toBeGreaterThanOrEqual($after->reserved)->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
});

it('receives a transfer once, however many people press receive at the same instant', function () {
    $sent = runStockOpsProbes([['inventory:ops-probe', ['send', "--lines={$this->tee}:10,{$this->mug}:4"]]])[0];
    expect($sent['ok'])->toBeTrue();

    $results = runStockOpsProbes(array_map(fn ($i) => ['inventory:ops-probe', ['receive', "--transfer={$sent['ref']}", '--actor-id='.$this->people[$i % 3]]], range(1, 6)));

    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1)->and(array_unique(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'reason')))->toBe(['transfer_not_open'])
        ->and(($this->level)($this->tee, 'shop')->on_hand)->toBe(10)->and(($this->level)($this->mug, 'shop')->on_hand)->toBe(4)->and(($this->level)($this->tee)->on_hand)->toBe(40)
        ->and(DB::table('stock_movements')->where('ref_id', $sent['ref'])->where('reason', 'transfer_in')->count())->toBe(2)->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
});

it('opens one stock-take per place when several people start one at the same instant', function () {
    $results = runStockOpsProbes(array_map(fn ($i) => ['inventory:ops-probe', ['open-count', '--location=main', '--actor-id='.$this->people[$i % 3]]], range(1, 5)));

    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1)->and(array_unique(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'reason')))->toBe(['count_already_open'])->and(DB::table('stock_counts')->count())->toBe(1);
});

it('approves a stock-take once and corrects stock once, even when two people approve and a third refuses at the same instant', function () {
    [$counter, $one, $two] = $this->people;
    $opened = runStockOpsProbes([['inventory:ops-probe', ['open-count', '--location=main', "--actor-id={$counter}"]]])[0]['ref'];
    runStockOpsProbes([['inventory:ops-probe', ['record-count', "--count={$opened}", "--variant={$this->tee}", '--qty=47']], ['inventory:ops-probe', ['record-count', "--count={$opened}", "--variant={$this->mug}", '--qty=52']]]);
    runStockOpsProbes([['inventory:ops-probe', ['submit-count', "--count={$opened}", "--actor-id={$counter}"]]]);

    $results = runStockOpsProbes([
        ['inventory:ops-probe', ['approve-count', "--count={$opened}", "--actor-id={$one}"]],
        ['inventory:ops-probe', ['approve-count', "--count={$opened}", "--actor-id={$two}"]],
        ['inventory:ops-probe', ['reject-count', "--count={$opened}", "--actor-id={$two}"]],
        ['inventory:ops-probe', ['approve-count', "--count={$opened}", "--actor-id={$counter}"]],   // the person who counted is never allowed
    ]);

    $status = DB::table('stock_counts')->where('public_id', $opened)->value('status');
    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1)->and($status)->toBeIn(['approved', 'rejected'])->and((int) DB::table('stock_counts')->where('public_id', $opened)->value('decided_by'))->not->toBe($counter);
    if ($status === 'approved') {
        expect(($this->level)($this->tee)->on_hand)->toBe(47)->and(($this->level)($this->mug)->on_hand)->toBe(52)->and(DB::table('stock_movements')->where('ref_id', $opened)->where('reason', 'count')->count())->toBe(2);
    } else {
        expect(($this->level)($this->tee)->on_hand)->toBe(50)->and(DB::table('stock_movements')->where('reason', 'count')->count())->toBe(0);
    }
    expect(app(StockLedgerVerifier::class)->verify())->toBe([]);
});
