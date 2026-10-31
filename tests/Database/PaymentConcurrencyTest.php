<?php

declare(strict_types=1);

use App\Modules\Orders\DTOs\OrderPaymentRequest;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Money under REAL concurrency (Phase 6 gate): every probe is its own PHP process with its own MariaDB connection, all released at the same
 * instant. The queue is switched off in the probes, so what we see is exactly what the payment code and the database enforce.
 * The "gateway" is the in-memory fake, told by each probe what to answer.
 */

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class]);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);

    /** An online order of ৳1,500.00 with a payment started (so attempt 1 exists). Returns [order id, merchant reference]. */
    $this->started = function (int $id): array {
        fakeOrders([$id], ['payment_method_code' => 'online', 'subtotal_minor' => 150000, 'grand_total_minor' => 150000]);
        app(InitiatePayment::class)->handle(new OrderPaymentRequest($id, (string) DB::table('orders')->where('id', $id)->value('public_id'), sprintf('VM-TEST-%06d', $id), 150000, 'BDT', 'online', 'Rahim', '+8801712345678', null, 'House 5', 'Dhaka', '1205', 2, true, 'Tee', 'en'));

        return [$id, sprintf('VM-TEST-%06d-1', $id)];
    };
    $this->count = fn (string $event) => DB::table('outbox_messages')->where('event_name', $event)->count();
    $this->payment = fn (int $orderId) => DB::table('payments')->where('order_id', $orderId)->first();
});

/**
 * @param  list<list<string>>  $argLists  artisan arguments for each process
 * @return list<array<string, mixed>>
 */
function runPaymentProbes(array $argLists, string $command = 'payments:probe'): array
{
    $startAt = microtime(true) + 4.0 + count($argLists) * 0.2;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (array $args) use ($env, $startAt, $command) {
        $withStart = $command === 'payments:probe' ? ['--start-at='.$startAt] : [];
        $p = new Process([PHP_BINARY, 'artisan', $command, ...$args, ...$withStart], base_path(), $env);
        $p->setTimeout(180)->start();

        return $p;
    }, $argLists);

    return array_map(function (Process $p) use ($command): array {
        $p->wait();
        if ($command !== 'payments:probe') {
            return ['ok' => $p->isSuccessful(), 'output' => trim($p->getOutput().$p->getErrorOutput())];
        }
        $lines = array_values(array_filter(explode("\n", trim($p->getOutput()))));
        $decoded = json_decode((string) end($lines), true);
        expect($decoded)->toBeArray('probe produced no JSON: '.$p->getOutput().$p->getErrorOutput());

        return $decoded;
    }, $processes);
}

it('lets exactly one of eight simultaneous verifications of one paid transaction take the money, and announces it once', function () {
    [$id, $ref] = ($this->started)(1);

    $results = runPaymentProbes(array_map(fn () => ['verify', "--ref={$ref}", '--answer=valid', '--bank-id=BANK-ONE'], range(1, 8)));

    $p = ($this->payment)($id);
    expect(array_unique(array_column($results, 'outcome')))->toBe(['paid'])->and($p->status)->toBe('paid')->and($p->captured_minor)->toBe(150000)
        ->and(DB::table('payment_transactions')->where('merchant_ref', $ref)->value('status'))->toBe('succeeded')->and(($this->count)('payments.succeeded'))->toBe(1)
        ->and(DB::table('reconciliation_exceptions')->count())->toBe(0);
});

it('takes the money once when two different attempts of one payment are both reported as paid at the same instant, and flags the other for refund', function () {
    [$id, $ref1] = ($this->started)(1);
    $this->travel(121)->seconds();
    app(InitiatePayment::class)->handle(new OrderPaymentRequest($id, (string) DB::table('orders')->where('id', $id)->value('public_id'), 'VM-TEST-000001', 150000, 'BDT', 'online', 'Rahim', '+8801712345678', null, 'House 5', 'Dhaka', '1205', 2, true, 'Tee', 'en'));
    $this->travelBack();
    $ref2 = 'VM-TEST-000001-2';

    $results = runPaymentProbes([['verify', "--ref={$ref1}", '--answer=valid', '--bank-id=BANK-A'], ['verify', "--ref={$ref2}", '--answer=valid', '--bank-id=BANK-B'], ['verify', "--ref={$ref1}", '--answer=valid', '--bank-id=BANK-A'], ['verify', "--ref={$ref2}", '--answer=valid', '--bank-id=BANK-B']]);

    expect(array_unique(array_column($results, 'outcome')))->toBe(['paid'])->and(($this->payment)($id)->captured_minor)->toBe(150000)->and(($this->payment)($id)->status)->toBe('paid')
        ->and(($this->count)('payments.succeeded'))->toBe(1)->and(DB::table('payment_transactions')->where('payment_id', ($this->payment)($id)->id)->pluck('status')->unique()->all())->toBe(['succeeded'])
        ->and(DB::table('reconciliation_exceptions')->where('type', 'duplicate_payment')->count())->toBe(1);
});

it('stores one notification delivered eight times at once exactly once, and pays once', function () {
    [$id, $ref] = ($this->started)(1);

    $results = runPaymentProbes(array_map(fn () => ['ipn', "--ref={$ref}", '--answer=valid', '--event-id=evt-same-1'], range(1, 8)));

    expect(count(array_filter($results, fn ($r) => $r['stored'] ?? false)))->toBe(1)->and(DB::table('payment_webhook_events')->count())->toBe(1)->and(DB::table('payment_webhook_events')->value('status'))->toBe('processed')
        ->and(($this->payment)($id)->captured_minor)->toBe(150000)->and(($this->count)('payments.succeeded'))->toBe(1);
});

it('never lets a "failed" report undo a payment that another process is confirming: paid, once, whatever the order they arrive in', function () {
    foreach ([1, 2, 3] as $round) {
        [$id, $ref] = ($this->started)($round);

        runPaymentProbes([
            ['ipn', "--ref={$ref}", '--answer=failed', '--claim=FAILED', "--event-id=fail-a-{$round}", '--validation-id=VAL-F1'],
            ['ipn', "--ref={$ref}", '--answer=valid', '--claim=VALID', "--event-id=ok-a-{$round}", '--validation-id=VAL-OK', '--bank-id=BANK-R'.$round],
            ['ipn', "--ref={$ref}", '--answer=failed', '--claim=FAILED', "--event-id=fail-b-{$round}", '--validation-id=VAL-F2'],
            ['ipn', "--ref={$ref}", '--answer=valid', '--claim=VALID', "--event-id=ok-b-{$round}", '--validation-id=VAL-OK2', '--bank-id=BANK-R'.$round],
            ['verify', "--ref={$ref}", '--answer=valid', '--bank-id=BANK-R'.$round],
        ]);

        $p = ($this->payment)($id);
        expect($p->status)->toBe('paid', "round {$round}")->and($p->captured_minor)->toBe(150000)->and(DB::table('payment_transactions')->where('payment_id', $p->id)->value('status'))->toBe('succeeded');
    }
    expect(($this->count)('payments.succeeded'))->toBe(3);   // one per order, never two for one
});

it('never loses money or invents a payment when a success races the expiry job: either paid, or expired and flagged as a late payment', function () {
    foreach ([1, 2, 3, 4] as $round) {
        [$id, $ref] = ($this->started)($round);
        DB::table('payments')->where('order_id', $id)->update(['expires_at' => now()->subMinute()]);

        runPaymentProbes([
            ['verify', "--ref={$ref}", '--answer=valid', '--bank-id=BANK-X'.$round],
            ['expire', "--ref={$ref}", '--answer=none'],   // the expiry job's own question to the gateway is answered "nothing yet"
            ['verify', "--ref={$ref}", '--answer=valid', '--bank-id=BANK-X'.$round],
            ['expire', "--ref={$ref}", '--answer=none'],
        ]);

        $p = ($this->payment)($id);
        $late = DB::table('reconciliation_exceptions')->where('payment_id', $p->id)->where('type', 'late_success')->count();
        $expiredEvent = DB::table('outbox_messages')->where('event_name', 'payments.expired')->where('payload->order_id', $id)->count();

        expect($p->captured_minor)->toBe(150000, "round {$round}: the money must be recorded");   // never lost
        if ($p->status === 'paid') {
            expect($expiredEvent)->toBe(0)->and($late)->toBe(0);   // the order is paid, so it must not also be told it expired
        } else {
            expect($p->status)->toBe('needs_review')->and($late)->toBe(1)->and($expiredEvent)->toBe(1);   // expired first: the late money waits for a person
        }
    }
});

it('delivers a payment announcement that was committed but never dispatched (a crash), once, even with four workers racing and a redelivery', function () {
    [$id, $ref] = ($this->started)(1);

    runPaymentProbes([['verify', "--ref={$ref}", '--answer=valid', '--bank-id=BANK-CRASH']]);   // commits the payment and its announcement; the queue is off, so nothing dispatches it: a "crash"

    $state = fn () => DB::table('orders')->where('id', $id)->first(['status', 'payment_status']);
    expect(($this->payment)($id)->status)->toBe('paid')->and($state()->payment_status)->toBe('pending')->and(DB::table('outbox_messages')->where('event_name', 'payments.succeeded')->value('status'))->toBe('pending');

    $workers = runPaymentProbes(array_map(fn () => ['--limit=10'], range(1, 4)), 'outbox:dispatch');   // the scheduler's safety net, four of them at once

    $paidMoves = fn () => DB::table('order_status_history')->where('order_id', $id)->where('axis', 'payment')->where('to_state', 'paid')->count();
    expect(array_unique(array_column($workers, 'ok')))->toBe([true])->and($state()->payment_status)->toBe('paid')->and($state()->status)->toBe('confirmed')->and($paidMoves())->toBe(1)
        ->and(DB::table('outbox_messages')->where('event_name', 'payments.succeeded')->value('status'))->toBe('done')
        ->and(DB::table('consumed_events')->where('consumer', 'orders.payment_succeeded')->count())->toBe(1);

    // the broker delivers it AGAIN (at-least-once): nothing changes
    DB::table('outbox_messages')->where('event_name', 'payments.succeeded')->update(['status' => 'pending', 'available_at' => now()->subMinute()]);
    $before = DB::table('order_status_history')->where('order_id', $id)->count();
    runPaymentProbes([['--limit=10'], ['--limit=10']], 'outbox:dispatch');

    expect(DB::table('order_status_history')->where('order_id', $id)->count())->toBe($before)->and($paidMoves())->toBe(1)->and($state()->payment_status)->toBe('paid')
        ->and(DB::table('consumed_events')->where('consumer', 'orders.payment_succeeded')->count())->toBe(1);
});
