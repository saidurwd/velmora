<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\RecordCodCollection;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Cash-on-delivery bookkeeping under REAL concurrency (Phase 6 gate): every probe is its own PHP process with its own MariaDB connection,
 * all released at the same instant. Rules proved: an order's cash is recorded once, a parcel sits on one courier statement, a statement is entered once.
 */

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class]);
    $this->staff = [catalogStaff([])->id, catalogStaff([])->id];
    $this->cod = function (int $id): string {
        fakeOrders([$id], ['payment_method_code' => 'cod', 'status' => 'confirmed', 'subtotal_minor' => 120000, 'grand_total_minor' => 120000, 'created_at' => now()->subDay(), 'placed_at' => now()->subDay()]);

        return (string) DB::table('orders')->where('id', $id)->value('public_id');
    };
    $this->collected = fn (int $id): string => app(RecordCodCollection::class)->handle(new Actor(ActorType::Staff, $this->staff[0], null, 'setup'), ($this->cod)($id))->public_id;
});

/**
 * @param  list<list<string>>  $argLists
 * @return list<array<string, mixed>>
 */
function runCodProbes(array $argLists): array
{
    $startAt = microtime(true) + 4.0 + count($argLists) * 0.2;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (array $args) use ($env, $startAt) {
        $p = new Process([PHP_BINARY, 'artisan', 'payments:cod-probe', ...$args, '--start-at='.$startAt], base_path(), $env);
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

it('records the cash of one delivery once when eight people press the button at the same instant', function () {
    $order = ($this->cod)(1);

    $results = runCodProbes(array_map(fn ($i) => ['collect', "--order={$order}", '--actor-id='.$this->staff[$i % 2]], range(1, 8)));

    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1)->and(array_unique(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'reason')))->toBe(['cod_already_collected'])
        ->and(DB::table('cod_collections')->count())->toBe(1)->and(DB::table('payments')->count())->toBe(1)->and(DB::table('payment_transactions')->count())->toBe(1)
        ->and((int) DB::table('payments')->value('captured_minor'))->toBe(120000)->and(DB::table('outbox_messages')->where('event_name', 'payments.succeeded')->count())->toBe(1);
});

it('records many different deliveries at once, each exactly once', function () {
    $orders = array_map(fn ($id) => ($this->cod)($id), range(1, 4));

    $results = runCodProbes(array_map(fn ($i) => ['collect', '--order='.$orders[$i % 4], '--actor-id='.$this->staff[0]], range(0, 11)));

    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(4)->and(DB::table('cod_collections')->count())->toBe(4)->and(DB::table('payments')->count())->toBe(4)->and((int) DB::table('payments')->sum('captured_minor'))->toBe(480000);
});

it('puts a parcel on one courier statement only when six statements claim it at the same instant', function () {
    $parcel = ($this->collected)(1);

    $results = runCodProbes(array_map(fn ($i) => ['remit', "--statement=ST-{$i}", '--received=120000', "--parcel={$parcel}", '--amount=120000', '--actor-id='.$this->staff[$i % 2]], range(1, 6)));

    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1)->and(array_unique(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'reason')))->toBe(['cod_already_remitted'])
        ->and(DB::table('cod_remittances')->count())->toBe(1)->and(DB::table('cod_remittance_items')->count())->toBe(1);
});

it('enters one courier statement once when five people enter it at the same instant', function () {
    $parcels = array_map(fn ($id) => ($this->collected)($id), range(1, 5));

    $results = runCodProbes(array_map(fn ($i) => ['remit', '--statement=ST-SAME', '--received=120000', "--parcel={$parcels[$i]}", '--amount=120000', '--actor-id='.$this->staff[0]], range(0, 4)));

    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1)->and(array_unique(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'reason')))->toBe(['cod_statement_duplicate'])
        ->and(DB::table('cod_remittances')->count())->toBe(1);
});

it('raises one exception for a short statement even when the same short statement is entered by many at once', function () {
    $parcel = ($this->collected)(1);

    runCodProbes(array_map(fn ($i) => ['remit', '--statement=ST-SHORT', '--received=100000', "--parcel={$parcel}", '--amount=120000', '--actor-id='.$this->staff[0]], range(1, 5)));

    expect(DB::table('cod_remittances')->count())->toBe(1)->and(DB::table('reconciliation_exceptions')->where('type', 'cod_short')->count())->toBe(1);
});
