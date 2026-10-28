<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Inventory\Services\StockLedgerVerifier;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

/*
 * Stock correctness under REAL concurrency (Phase 3 gate): every probe is a separate PHP process with its own MariaDB
 * connection, all released at the same instant. The unit tests prove the logic; these prove the database enforces it.
 */

beforeEach(function () {
    Queue::fake();
    $this->seed([LanguageSeeder::class, StockLocationSeeder::class]);
    $actor = Actor::system('test');
    $category = app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', 'Clothing')]);

    $this->variantIds = [];
    foreach (range(1, 3) as $i) {
        $product = app(SaveProduct::class)->handle($actor, null, new ProductData(ProductType::Physical, [new TranslationInput('en', "Item {$i}")], categoryIds: [$category->id], defaultVariant: new VariantData("ITEM-{$i}", trackInventory: true)));
        $this->variantIds[$i] = (int) $product->variants()->value('id');
    }
});

function putStock(int $variantId, int $qty): void
{
    app(AdjustStock::class)->handle(Actor::system('test'), $variantId, 'main', $qty, 'receipt');
}

/**
 * Starts one OS process per argument list, releases them all at the same instant, and returns their decoded JSON results.
 *
 * @param  list<list<string>>  $argLists  artisan arguments for each process
 * @return list<array<string, mixed>>
 */
function runTogether(array $argLists): array
{
    $startAt = microtime(true) + 4.0 + count($argLists) * 0.15; // time for every process to boot before the starting gun
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (array $args) use ($env, $startAt) {
        $process = new Process([PHP_BINARY, 'artisan', 'inventory:probe', ...$args, '--start-at='.$startAt], base_path(), $env);
        $process->setTimeout(120)->start();

        return $process;
    }, $argLists);

    return array_map(function (Process $process): array {
        $process->wait();
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        $decoded = json_decode((string) end($lines), true);

        expect($decoded)->toBeArray('probe produced no JSON: '.$process->getOutput().$process->getErrorOutput());

        return $decoded;
    }, $processes);
}

function outcomes(array $results): array
{
    $counts = array_count_values(array_map(fn (array $r) => $r['ok'] ? 'ok' : (string) $r['code'], $results));
    ksort($counts); // compare outcomes, not the order processes finished in

    return $counts;
}

it('sells the last unit exactly once when 10 processes buy it at the same instant', function () {
    putStock($this->variantIds[1], 1);

    $results = runTogether(array_map(fn ($i) => ['reserve', '--variant='.$this->variantIds[1], '--qty=1', "--owner=ORD-{$i}"], range(1, 10)));

    expect(outcomes($results))->toBe(['ok' => 1, 'stock.insufficient' => 9])
        ->and((int) DB::table('stock_levels')->where('variant_id', $this->variantIds[1])->value('reserved'))->toBe(1)
        ->and(DB::table('stock_reservations')->where('status', 'active')->count())->toBe(1)
        ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
});

it('never oversells a limited stock: 12 buyers, 5 units, exactly 5 succeed', function () {
    putStock($this->variantIds[1], 5);

    $results = runTogether(array_map(fn ($i) => ['reserve', '--variant='.$this->variantIds[1], '--qty=1', "--owner=ORD-{$i}"], range(1, 12)));

    expect(outcomes($results))->toBe(['ok' => 5, 'stock.insufficient' => 7])
        ->and((int) DB::table('stock_levels')->where('variant_id', $this->variantIds[1])->value('reserved'))->toBe(5)
        ->and(DB::table('stock_levels')->whereRaw('reserved > on_hand OR reserved < 0')->count())->toBe(0)
        ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
});

it('handles multi-line baskets submitted in opposite order without deadlock errors', function () {
    [$a, $b] = [$this->variantIds[1], $this->variantIds[2]];
    putStock($a, 50);
    putStock($b, 50);

    $results = runTogether(array_merge(
        array_map(fn ($i) => ['lines', "--lines={$a}:1,{$b}:1", "--owner=AB-{$i}"], range(1, 6)),
        array_map(fn ($i) => ['lines', "--lines={$b}:1,{$a}:1", "--owner=BA-{$i}"], range(1, 6)),
    ));

    expect(outcomes($results))->toBe(['ok' => 12]) // no deadlock, no lock-wait failure, ever visible to the caller
        ->and((int) DB::table('stock_levels')->where('variant_id', $a)->value('reserved'))->toBe(12)
        ->and((int) DB::table('stock_levels')->where('variant_id', $b)->value('reserved'))->toBe(12)
        ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
});

it('keeps baskets atomic under contention: no half-reserved baskets when stock runs out', function () {
    [$a, $b] = [$this->variantIds[1], $this->variantIds[2]];
    putStock($a, 4);
    putStock($b, 4);

    $results = runTogether(array_merge(
        array_map(fn ($i) => ['lines', "--lines={$a}:1,{$b}:1", "--owner=AB-{$i}"], range(1, 5)),
        array_map(fn ($i) => ['lines', "--lines={$b}:1,{$a}:1", "--owner=BA-{$i}"], range(1, 5)),
    ));

    $reservedA = (int) DB::table('stock_levels')->where('variant_id', $a)->value('reserved');
    $reservedB = (int) DB::table('stock_levels')->where('variant_id', $b)->value('reserved');

    expect(outcomes($results))->toBe(['ok' => 4, 'stock.insufficient' => 6])
        ->and($reservedA)->toBe(4)->and($reservedB)->toBe(4)                    // every successful basket took BOTH lines
        ->and(DB::table('stock_reservations')->where('variant_id', $a)->count())->toBe(DB::table('stock_reservations')->where('variant_id', $b)->count())
        ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
});

it('ships a reservation exactly once when 8 processes commit it simultaneously', function () {
    putStock($this->variantIds[1], 10);
    $reservation = app(ReserveStock::class)->handle($this->variantIds[1], 4, 'order', 'ORD-1');

    $results = runTogether(array_map(fn () => ['commit', '--reservation='.$reservation->public_id], range(1, 8)));

    expect(count(array_filter($results, fn ($r) => $r['ok'] && $r['committed'] === true)))->toBe(1)
        ->and(count(array_filter($results, fn ($r) => $r['ok'] && $r['committed'] === false)))->toBe(7)
        ->and((int) DB::table('stock_levels')->where('variant_id', $this->variantIds[1])->value('on_hand'))->toBe(6)
        ->and(DB::table('stock_movements')->where('reason', 'shipment')->count())->toBe(1)
        ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
});

it('never both ships and releases a reservation when commit and release race', function () {
    foreach (range(1, 4) as $round) {
        $variant = $this->variantIds[($round % 3) + 1];
        putStock($variant, 5);
        $reservation = app(ReserveStock::class)->handle($variant, 2, 'order', "ORD-R{$round}");

        runTogether([['commit', '--reservation='.$reservation->public_id], ['release', '--reservation='.$reservation->public_id]]);

        $status = DB::table('stock_reservations')->where('id', $reservation->id)->value('status');
        $level = DB::table('stock_levels')->where('variant_id', $variant)->first();

        expect($status)->toBeIn(['committed', 'released'])->and((int) $level->reserved)->toBe(0)->and(app(StockLedgerVerifier::class)->verify())->toBe([]);

        // exactly one outcome is reflected in the counts
        $shipped = DB::table('stock_movements')->where('reason', 'shipment')->where('ref_id', "ORD-R{$round}")->count();
        expect($status === 'committed' ? $shipped : -$shipped)->toBe($status === 'committed' ? 1 : 0);
    }
});
