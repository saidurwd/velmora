<?php

declare(strict_types=1);

use App\Modules\Orders\DTOs\OrderPaymentRequest;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Reconciliation under REAL concurrency (Phase 6 gate): every probe is its own PHP process with its own MariaDB connection, all released at the
 * same instant. Rules proved: a settlement file is checked once, each problem is raised once, an exception is closed by one person only.
 */

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class]);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    $this->staff = [catalogStaff([])->id, catalogStaff([])->id];
    $this->paid = function (int $id): string {
        fakeOrders([$id], ['payment_method_code' => 'online', 'subtotal_minor' => 150000, 'grand_total_minor' => 150000]);
        app(InitiatePayment::class)->handle(new OrderPaymentRequest($id, (string) DB::table('orders')->where('id', $id)->value('public_id'), sprintf('VM-TEST-%06d', $id), 150000, 'BDT', 'online', 'Rahim', '+8801712345678', null, 'House 5', 'Dhaka', '1205', 2, true, 'Tee', 'en'));
        $ref = sprintf('VM-TEST-%06d-1', $id);
        app(FakeGateway::class)->answer($ref, new VerifiedPayment(VerifiedStatus::Valid, $ref, Money::of(150000), 'BANK-'.$id, 'VAL-'.$id));
        app(VerifyPayment::class)->handle($ref);

        return $ref;
    };
    $this->file = function (array $rows): string {
        $path = sys_get_temp_dir().'/velmora-settlement-'.bin2hex(random_bytes(6)).'.csv';
        file_put_contents($path, "reference,bank_txn_id,amount,currency,settled_at\n".implode("\n", array_map(fn ($r) => implode(',', $r), $rows)));

        return $path;
    };
});

/**
 * @param  list<list<string>>  $argLists
 * @return list<array<string, mixed>>
 */
function runReconcileProbes(array $argLists): array
{
    $startAt = microtime(true) + 4.0 + count($argLists) * 0.2;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (array $args) use ($env, $startAt) {
        $p = new Process([PHP_BINARY, 'artisan', 'payments:reconcile-probe', ...$args, '--start-at='.$startAt], base_path(), $env);
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

it('checks one settlement file once when five people upload it at the same instant, and raises each problem once', function () {
    $a = ($this->paid)(1);
    ($this->paid)(2);   // paid here, missing from the file: an orphan
    $path = ($this->file)([[$a, 'BANK-1', '1499.00', 'BDT', '2026-10-31T10:00:00Z'], ['GHOST-1', 'GHOST-1', '10.00', 'BDT', '2026-10-31T10:00:00Z']]);

    $results = runReconcileProbes(array_map(fn ($i) => ['import', "--file={$path}", '--actor-id='.$this->staff[$i % 2]], range(1, 5)));
    @unlink($path);

    $refused = array_values(array_filter($results, fn ($r) => ! $r['ok']));
    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBeGreaterThanOrEqual(1)->and(DB::table('settlement_imports')->count())->toBe(1)->and(array_unique(array_column($refused, 'reason')))->each->toBe('settlement_already_imported')
        ->and(DB::table('reconciliation_exceptions')->where('type', 'amount_mismatch')->count())->toBe(1)->and(DB::table('reconciliation_exceptions')->where('type', 'unmatched_provider_txn')->count())->toBe(1)
        ->and(DB::table('reconciliation_exceptions')->where('type', 'orphan_payment')->count())->toBe(1)->and(DB::table('reconciliation_exceptions')->count())->toBe(3);
});

it('lets exactly one of six people close an exception at the same instant', function () {
    ($this->paid)(1);
    $path = ($this->file)([['GHOST-1', 'GHOST-1', '10.00', 'BDT', '2026-10-31T10:00:00Z'], ['VM-TEST-000001-1', 'BANK-1', '1500.00', 'BDT', '2026-10-31T10:00:00Z']]);
    runReconcileProbes([['import', "--file={$path}", '--actor-id='.$this->staff[0]]]);
    @unlink($path);
    $exception = DB::table('reconciliation_exceptions')->value('public_id');

    $results = runReconcileProbes(array_map(fn ($i) => ['resolve', "--exception={$exception}", '--outcome='.($i % 2 ? 'resolved' : 'ignored'), '--actor-id='.$this->staff[$i % 2]], range(1, 6)));

    $row = DB::table('reconciliation_exceptions')->where('public_id', $exception)->first();
    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1)->and(array_unique(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'reason')))->toBe(['exception_not_open'])
        ->and($row->status)->toBeIn(['resolved', 'ignored'])->and(DB::table('audit_events')->whereIn('action', ['reconciliation.resolved', 'reconciliation.ignored'])->count())->toBe(1)
        ->and($row->status)->toBe(array_values(array_filter($results, fn ($r) => $r['ok']))[0]['status']);
});
