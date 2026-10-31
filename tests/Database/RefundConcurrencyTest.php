<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * Refunds under REAL concurrency (Phase 6 gate): every probe is its own PHP process with its own MariaDB connection, all released at
 * the same instant. The rule being proved: money given back (asked for, promised, in flight or done) can never pass money received.
 */

beforeEach(function () {
    $this->seed([LanguageSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->staff = [catalogStaff([])->id, catalogStaff([])->id];

    /** A paid payment of ৳1,500.00 with its paid attempt (bank id BANK-n), written directly. Returns [payment public id, payment id]. */
    $this->paid = function (int $orderId = 1): array {
        fakeOrders([$orderId], ['payment_method_code' => 'online', 'subtotal_minor' => 150000, 'grand_total_minor' => 150000]);
        $publicId = strtolower((string) Str::ulid());
        $id = DB::table('payments')->insertGetId(['public_id' => $publicId, 'order_id' => $orderId, 'method_code' => 'online', 'provider' => 'fake', 'amount_minor' => 150000, 'currency' => 'BDT', 'status' => 'paid', 'captured_minor' => 150000, 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payment_transactions')->insert(['public_id' => strtolower((string) Str::ulid()), 'payment_id' => $id, 'provider' => 'fake', 'merchant_ref' => sprintf('VM-TEST-%06d-1', $orderId), 'attempt' => 1, 'status' => 'succeeded', 'provider_txn_id' => 'BANK-'.$orderId, 'amount_minor' => 150000, 'currency' => 'BDT', 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return [$publicId, $id];
    };
    $this->books = function (int $paymentId): void {
        $primary = DB::table('payment_transactions')->where('payment_id', $paymentId)->where('status', 'succeeded')->orderBy('id')->value('id');
        $reserved = (int) DB::table('refunds')->where('payment_id', $paymentId)->where('payment_transaction_id', $primary)->whereIn('status', ['requested', 'approved', 'processing', 'succeeded'])->sum('amount_minor');
        $p = DB::table('payments')->where('id', $paymentId)->first();
        expect((int) $p->refunded_minor)->toBe($reserved)->and($reserved)->toBeLessThanOrEqual((int) $p->captured_minor);
    };
});

/**
 * @param  list<list<string>>  $argLists
 * @return list<array<string, mixed>>
 */
function runRefundProbes(array $argLists): array
{
    $startAt = microtime(true) + 4.0 + count($argLists) * 0.2;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (array $args) use ($env, $startAt) {
        $p = new Process([PHP_BINARY, 'artisan', 'payments:refund-probe', ...$args, '--start-at='.$startAt], base_path(), $env);
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

it('lets exactly one of six simultaneous requests for 60% of the money through', function () {
    [$public, $id] = ($this->paid)();

    $results = runRefundProbes(array_map(fn ($i) => ['request', "--payment={$public}", '--amount=90000', '--actor-id='.$this->staff[$i % 2]], range(1, 6)));

    $accepted = array_filter($results, fn ($r) => $r['ok']);
    $refused = array_values(array_filter($results, fn ($r) => ! $r['ok']));
    expect($accepted)->toHaveCount(1)->and(array_unique(array_column($refused, 'reason')))->toBe(['refund_exceeds_captured'])->and(DB::table('refunds')->where('payment_id', $id)->count())->toBe(1)
        ->and((int) DB::table('payments')->where('id', $id)->value('refunded_minor'))->toBe(90000);
    ($this->books)($id);
});

it('lets exactly ten of twelve simultaneous requests for 10% through, and never an eleventh', function () {
    [$public, $id] = ($this->paid)();

    $results = runRefundProbes(array_map(fn ($i) => ['request', "--payment={$public}", '--amount=15000', '--actor-id='.$this->staff[$i % 2]], range(1, 12)));

    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(10)->and(count(array_filter($results, fn ($r) => ! $r['ok'] && $r['reason'] === 'refund_exceeds_captured')))->toBe(2)
        ->and((int) DB::table('payments')->where('id', $id)->value('refunded_minor'))->toBe(150000)->and((int) DB::table('refunds')->where('payment_id', $id)->sum('amount_minor'))->toBe(150000);
    ($this->books)($id);
});

it('fits exactly as many as the money allows when the amounts do not divide evenly', function () {
    [$public, $id] = ($this->paid)();

    $results = runRefundProbes(array_map(fn ($i) => ['request', "--payment={$public}", '--amount=40000', '--actor-id='.$this->staff[$i % 2]], range(1, 6)));

    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(3)->and((int) DB::table('payments')->where('id', $id)->value('refunded_minor'))->toBe(120000);
    ($this->books)($id);
});

it('keeps the books balanced when requests race a rejection that gives money back', function () {
    [$public, $id] = ($this->paid)();
    $first = runRefundProbes([['request', "--payment={$public}", '--amount=100000', '--actor-id='.$this->staff[0]]])[0]['refund'];

    runRefundProbes([
        ['reject', "--refund={$first}", '--actor-id='.$this->staff[1]],
        ['request', "--payment={$public}", '--amount=100000', '--actor-id='.$this->staff[0]],
        ['request', "--payment={$public}", '--amount=100000', '--actor-id='.$this->staff[1]],
        ['request', "--payment={$public}", '--amount=50000', '--actor-id='.$this->staff[0]],
    ]);

    ($this->books)($id);
    expect((int) DB::table('payments')->where('id', $id)->value('refunded_minor'))->toBeLessThanOrEqual(150000);
});

it('approves a refund once when four people press approve at the same instant', function () {
    [$public, $id] = ($this->paid)();
    $refund = runRefundProbes([['request', "--payment={$public}", '--amount=100000', '--actor-id='.$this->staff[0]]])[0]['refund'];

    $results = runRefundProbes(array_map(fn ($i) => ['approve', "--refund={$refund}", '--actor-id='.$this->staff[1]], range(1, 4)));

    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1)->and(array_unique(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'reason')))->toBe(['refund_not_pending'])
        ->and(DB::table('refunds')->where('public_id', $refund)->value('status'))->toBe('approved')->and((int) DB::table('refunds')->where('public_id', $refund)->value('approved_by'))->toBe($this->staff[1]);
});

it('sends an approved refund to the gateway once when six people press send at the same instant', function () {
    [$public, $id] = ($this->paid)();
    $refund = runRefundProbes([['request', "--payment={$public}", '--amount=150000', '--actor-id='.$this->staff[0]]])[0]['refund'];
    runRefundProbes([['approve', "--refund={$refund}", '--actor-id='.$this->staff[1]]]);

    $results = runRefundProbes(array_map(fn ($i) => ['execute', "--refund={$refund}", '--actor-id='.$this->staff[1]], range(1, 6)));

    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1)->and(array_sum(array_column($results, 'gateway_calls')))->toBe(1)   // the gateway was asked once in total
        ->and(array_unique(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'reason')))->toBe(['refund_already_processing'])
        ->and(DB::table('refunds')->where('public_id', $refund)->value('status'))->toBe('processing')->and(DB::table('refunds')->where('public_id', $refund)->value('provider_refund_id'))->not->toBeNull();
    ($this->books)($id);
});

it('cannot approve and reject the same request both ways at the same instant', function () {
    [$public, $id] = ($this->paid)();
    $refund = runRefundProbes([['request', "--payment={$public}", '--amount=100000', '--actor-id='.$this->staff[0]]])[0]['refund'];

    $results = runRefundProbes([['approve', "--refund={$refund}", '--actor-id='.$this->staff[1]], ['reject', "--refund={$refund}", '--actor-id='.$this->staff[1]], ['approve', "--refund={$refund}", '--actor-id='.$this->staff[1]], ['reject', "--refund={$refund}", '--actor-id='.$this->staff[1]]]);

    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1)->and(DB::table('refunds')->where('public_id', $refund)->value('status'))->toBeIn(['approved', 'rejected']);
    ($this->books)($id);
});
