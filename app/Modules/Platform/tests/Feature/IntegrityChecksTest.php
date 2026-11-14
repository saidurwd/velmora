<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Platform\Actions\RunIntegrityChecks;
use App\Modules\Platform\Contracts\IntegrityCheck;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\IntegrityResult;
use App\Modules\Platform\Exceptions\OpsException;
use App\Modules\Platform\Jobs\RunIntegrityChecksJob;
use App\Modules\Platform\Support\Integrity\StuckOutboxCheck;
use App\Modules\Platform\Support\IntegrityRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * Phase 10, slice 4b: the data-integrity checks. Each reads its own module's tables and reports; nothing is ever repaired.
 */

beforeEach(function () {
    shippingWorld($this);
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->actor = Actor::system('t');
    $this->viewer = catalogStaff(['system.view']);
    $this->variant = (int) DB::table('product_variants')->value('id');
    $this->run = fn (string $code) => app(RunIntegrityChecks::class)->handle(null, $code)[$code];
    $this->last = fn (string $code) => DB::table('integrity_check_runs')->where('code', $code)->first();
    $this->samples = fn (string $code) => (array) json_decode((string) ($this->last)($code)->samples, true);

    /** An order with lines worth $linesMinor but a stored subtotal of $subtotalMinor. */
    $this->orderWithLines = function (int $id, int $subtotalMinor, int $linesMinor, ?string $createdAt = null) {
        fakeOrders([$id], ['subtotal_minor' => $subtotalMinor, 'grand_total_minor' => $subtotalMinor, 'created_at' => $createdAt ?? now(), 'public_id' => 'ord'.str_pad((string) $id, 23, '0', STR_PAD_LEFT)]);
        DB::table('order_items')->insert(['order_id' => $id, 'variant_id' => $this->variant, 'product_type' => 'physical', 'name_snapshot' => '{"en":"x","bn":"x"}', 'sku_snapshot' => 'X', 'quantity' => 2, 'unit_price_minor' => intdiv($linesMinor, 2),
            'discount_minor' => 0, 'tax_rate_bp' => 0, 'tax_minor' => 0, 'line_total_minor' => $linesMinor, 'created_at' => now(), 'updated_at' => now()]);
    };
    /** A paid payment of ৳1,500 and refunds on it: [[amount, status], …]. */
    $this->paymentWithRefunds = function (int $orderId, array $refunds, int $captured = 150000) {
        fakeOrders([$orderId], ['payment_method_code' => 'online']);
        $pid = DB::table('payments')->insertGetId(['public_id' => 'pay'.str_pad((string) $orderId, 23, '0', STR_PAD_LEFT), 'order_id' => $orderId, 'method_code' => 'online', 'provider' => 'fake', 'amount_minor' => $captured, 'currency' => 'BDT',
            'captured_minor' => $captured, 'status' => 'paid', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($refunds as $i => [$amount, $status]) {
            DB::table('refunds')->insert(['public_id' => strtolower((string) Str::ulid()), 'payment_id' => $pid, 'order_id' => $orderId, 'amount_minor' => $amount, 'currency' => 'BDT', 'status' => $status, 'method' => 'manual',
                'reason' => 'test', 'refund_ref' => 'RF-'.str_pad((string) ($orderId * 10 + $i), 20, 'X', STR_PAD_LEFT), 'created_at' => now(), 'updated_at' => now()]);
        }
    };
    $this->coupon = fn (string $code, int $used) => DB::table('coupons')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'code' => $code, 'type' => 'percent', 'value' => 1000, 'scope' => 'all', 'is_active' => true, 'min_spend_minor' => 0, 'used_count' => $used, 'created_at' => now(), 'updated_at' => now()]);
    $this->outbox = fn (string $status, string $availableAgo = 'PT0S') => DB::table('outbox_messages')->insert(['public_id' => strtolower((string) Str::ulid()), 'event_name' => 'x.y', 'payload' => '{}', 'status' => $status, 'attempts' => 0, 'available_at' => now()->sub(new DateInterval($availableAgo)), 'created_at' => now()]);
});

describe('the registry', function () {
    it('holds the checks the modules registered, each once, with a name in both languages', function () {
        $codes = array_map(fn (IntegrityCheck $c) => $c->code(), app(IntegrityRegistry::class)->checks());

        expect($codes)->toEqualCanonicalizing(['stock_ledger', 'stock_reservations', 'refunds_within_captured', 'order_lines_add_up', 'coupon_use_counts', 'outbox_not_stuck']);
        foreach ($codes as $code) {
            expect(trans()->has("platform::ops.checks.{$code}", 'en'))->toBeTrue()->and(trans()->has("platform::ops.checks.{$code}", 'bn'))->toBeTrue();
        }
        app(IntegrityRegistry::class)->add(StuckOutboxCheck::class);   // adding twice changes nothing
        expect(app(IntegrityRegistry::class)->checks())->toHaveCount(6)->and(fn () => app(IntegrityRegistry::class)->find('nope'))->toThrow(InvalidArgumentException::class);
    });
});

describe('on healthy data', function () {
    it('finds nothing wrong, and keeps one row per check however often it runs', function () {
        ($this->orderWithLines)(901, 100000, 100000);
        ($this->paymentWithRefunds)(902, [[50000, 'succeeded'], [20000, 'requested']]);
        ($this->coupon)('OKAY', 0);
        ($this->outbox)('done', 'PT1H');
        ($this->outbox)('pending', 'PT0S');

        $first = app(RunIntegrityChecks::class)->handle();
        app(RunIntegrityChecks::class)->handle();

        expect($first)->toHaveCount(6)->and(collect($first)->pluck('status')->unique()->all())->toBe(['ok'])->and(DB::table('integrity_check_runs')->count())->toBe(6)
            ->and(DB::table('integrity_check_runs')->where('status', 'ok')->where('problems', 0)->count())->toBe(6);
    });
});

describe('the stock checks', function () {
    it('find stock that is not in the ledger and stock held with no reservation behind it', function () {
        expect(($this->run)('stock_ledger')['status'])->toBe('ok')->and(($this->run)('stock_reservations')['status'])->toBe('ok');

        DB::table('stock_levels')->where('variant_id', $this->variant)->limit(1)->update(['on_hand' => DB::raw('on_hand + 5')]);
        $level = DB::table('stock_levels')->where('variant_id', $this->variant)->first();
        DB::table('stock_levels')->where('variant_id', $this->variant)->limit(1)->update(['reserved' => 1]);

        $ledger = ($this->run)('stock_ledger');
        $reservations = ($this->run)('stock_reservations');

        expect($ledger)->toBe(['status' => 'problem', 'problems' => 1])->and($reservations)->toBe(['status' => 'problem', 'problems' => 1])
            ->and(($this->samples)('stock_ledger')[0])->toContain('on_hand_vs_ledger')->toContain("variant {$this->variant}")
            ->and(($this->samples)('stock_reservations')[0])->toContain('reserved 1')->toContain('active reservations 0');
        expect($level)->not->toBeNull();
    });
});

describe('the refund check', function () {
    it('finds refunds that add up to more than was received, and ignores refunds that failed or were rejected', function () {
        ($this->paymentWithRefunds)(910, [[100000, 'succeeded'], [60000, 'approved']]);                  // 160,000 against 150,000 received
        ($this->paymentWithRefunds)(911, [[100000, 'succeeded'], [90000, 'failed'], [90000, 'rejected']]);   // only 100,000 counts
        ($this->paymentWithRefunds)(912, [[150000, 'succeeded']]);                                          // exactly all of it is fine

        $result = ($this->run)('refunds_within_captured');

        expect($result)->toBe(['status' => 'problem', 'problems' => 1])->and(($this->samples)('refunds_within_captured'))->toBe(['payment pay'.str_pad('910', 23, '0', STR_PAD_LEFT).': received 150000, refunds 160000 (minor units)']);
    });
});

describe('the order check', function () {
    it('finds a recent order whose lines do not add up to its subtotal, and leaves old ones alone', function () {
        ($this->orderWithLines)(920, 100000, 100000);                                   // balances
        ($this->orderWithLines)(921, 100000, 130000);                                   // does not: lines come to more
        ($this->orderWithLines)(923, 100000, 70000);                                    // does not: lines come to less
        ($this->orderWithLines)(922, 100000, 130000, now()->subDays(200)->toDateTimeString());   // does not, but is long settled and append-only

        $result = ($this->run)('order_lines_add_up');

        expect($result)->toBe(['status' => 'problem', 'problems' => 2])->and(($this->samples)('order_lines_add_up'))->toEqualCanonicalizing(['order ord'.str_pad('921', 23, '0', STR_PAD_LEFT).': subtotal 100000, lines 130000 (minor units)', 'order ord'.str_pad('923', 23, '0', STR_PAD_LEFT).': subtotal 100000, lines 70000 (minor units)']);

        config(['ops.integrity.window_days' => 365]);
        expect(($this->run)('order_lines_add_up')['problems'])->toBe(3);               // a wider window finds the old one too
    });
});

describe('the coupon check', function () {
    it('finds a coupon whose use count differs from its redemptions, either way', function () {
        $good = ($this->coupon)('GOOD', 1);
        $high = ($this->coupon)('HIGH', 3);
        $low = ($this->coupon)('LOW', 0);
        foreach ([[$good, 1], [$high, 1], [$low, 2]] as [$coupon, $order]) {
            fakeOrders([930 + $order]);
            DB::table('coupon_redemptions')->insert(['coupon_id' => $coupon, 'order_id' => 930 + $order, 'redeemer' => 'u:1', 'amount_minor' => 100, 'redeemed_at' => now()]);
        }
        fakeOrders([940]);
        DB::table('coupon_redemptions')->insert(['coupon_id' => $low, 'order_id' => 940, 'redeemer' => 'u:2', 'amount_minor' => 100, 'redeemed_at' => now()]);

        $result = ($this->run)('coupon_use_counts');

        expect($result)->toBe(['status' => 'problem', 'problems' => 2])->and(count(($this->samples)('coupon_use_counts')))->toBe(2);
    });
});

describe('the outbox check', function () {
    it('finds messages waiting too long and messages that gave up, not fresh or delivered ones', function () {
        ($this->outbox)('pending', 'PT0S');      // fresh
        ($this->outbox)('pending', 'PT2H');      // stuck
        ($this->outbox)('done', 'PT5H');         // delivered long ago
        ($this->outbox)('failed', 'PT1H');       // gave up

        $result = ($this->run)('outbox_not_stuck');

        expect($result)->toBe(['status' => 'problem', 'problems' => 2])->and(($this->last)('outbox_not_stuck')->note)->toBe('stuck 1, given up 1')->and(count(($this->samples)('outbox_not_stuck')))->toBe(2);
    });
});

describe('running them', function () {
    it('keeps only a few examples and stops counting at the limit', function () {
        config(['ops.integrity.sample' => 2, 'ops.integrity.max_problems' => 3]);
        foreach (range(1, 6) as $i) {
            ($this->outbox)('failed', 'PT1H');
        }

        $result = ($this->run)('outbox_not_stuck');

        expect($result['problems'])->toBe(3)->and(count(($this->samples)('outbox_not_stuck')))->toBe(2);
    });

    it('records a check that cannot run as an error, without stopping the others, and without secrets', function () {
        app(IntegrityRegistry::class)->add(get_class(new class implements IntegrityCheck
        {
            public function code(): string
            {
                return 'always_breaks';
            }

            public function run(): IntegrityResult
            {
                throw new RuntimeException("cannot reach the thing (password=hunter2)\nsecond line");
            }
        }));
        Log::spy();

        $outcome = app(RunIntegrityChecks::class)->handle();

        expect($outcome['always_breaks']['status'])->toBe('error')->and($outcome['outbox_not_stuck']['status'])->toBe('ok')->and(count($outcome))->toBe(7)
            ->and(($this->last)('always_breaks')->note)->toContain('cannot reach the thing')->not->toContain('hunter2')->not->toContain('second line');
        Log::shouldHaveReceived('error')->withArgs(fn (string $m) => $m === 'ops.integrity_error');
    });

    it('logs nothing as an error when everything is well', function () {
        Log::spy();

        app(RunIntegrityChecks::class)->handle();

        Log::shouldNotHaveReceived('error');
    });

    it('stores only the configured number of examples even when a check returns more', function () {
        config(['ops.integrity.sample' => 3]);
        app(IntegrityRegistry::class)->add(get_class(new class implements IntegrityCheck
        {
            public function code(): string
            {
                return 'many_examples';
            }

            public function run(): IntegrityResult
            {
                return new IntegrityResult(20, array_map(fn (int $i) => "thing {$i}", range(1, 20)));
            }
        }));

        app(RunIntegrityChecks::class)->handle(null, 'many_examples');

        expect(($this->samples)('many_examples'))->toBe(['thing 1', 'thing 2', 'thing 3'])->and(($this->last)('many_examples')->problems)->toBe(20);
    });

    it('runs one check by its code, and refuses a code that does not exist', function () {
        $outcome = app(RunIntegrityChecks::class)->handle(null, 'coupon_use_counts');

        expect(array_keys($outcome))->toBe(['coupon_use_counts'])->and(DB::table('integrity_check_runs')->count())->toBe(1);
        expect(fn () => app(RunIntegrityChecks::class)->handle(null, 'nope'))->toThrow(OpsException::class, 'check_unknown');
    });

    it('audits a run started by a person, not the nightly one, and logs what it finds', function () {
        Log::spy();
        ($this->outbox)('failed', 'PT1H');

        app(RunIntegrityChecks::class)->handle();                                          // nightly: nobody to name
        expect(DB::table('audit_events')->where('action', 'ops.integrity_run')->count())->toBe(0);

        app(RunIntegrityChecks::class)->handle($this->actor, 'outbox_not_stuck');
        $audit = DB::table('audit_events')->where('action', 'ops.integrity_run')->get();
        expect($audit)->toHaveCount(1)->and(json_decode($audit[0]->after, true))->toBe(['checks' => ['outbox_not_stuck'], 'outcome' => ['outbox_not_stuck' => 'problem']]);
        Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $c) => $m === 'ops.integrity_problem' && $c['check'] === 'outbox_not_stuck')->atLeast()->once();
    });

    it('only ever reads business data: the one thing it writes is its own result', function () {
        ($this->orderWithLines)(950, 100000, 130000);
        ($this->paymentWithRefunds)(951, [[200000, 'succeeded']]);
        ($this->coupon)('X', 5);
        ($this->outbox)('failed', 'PT1H');

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(RunIntegrityChecks::class)->handle();
        $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q) => preg_match('/^\s*(insert|update|delete|replace|alter|drop|truncate)/i', $q))->map(fn (string $q) => (string) preg_replace('/^\s*(insert into|update|delete from|replace into)\s+`?(\w+)`?.*$/is', '$2', $q))->unique()->values()->all();
        DB::disableQueryLog();

        expect($writes)->toBe(['integrity_check_runs']);
    });

    it('runs from the nightly job and is on the schedule every night', function () {
        ($this->outbox)('failed', 'PT1H');
        (new RunIntegrityChecksJob)->handle(app(RunIntegrityChecks::class));

        expect(DB::table('integrity_check_runs')->count())->toBe(6);
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'ops:integrity');
        expect($event)->not->toBeNull()->and($event->expression)->toBe('40 3 * * *')->and($event->withoutOverlapping)->toBeTrue();
    });
});

describe('the page', function () {
    it('shows each check\'s last result, with examples, and checks never run', function () {
        ($this->outbox)('failed', 'PT1H');
        ($this->run)('outbox_not_stuck');

        $html = ($this->actingAs($this->viewer, 'admin'))->get('/admin/ops/integrity')->assertOk()->assertSee('Data checks')->assertSee('No delivery message is stuck or given up')->assertSee('Needs a look')
            ->assertSee('1 found')->assertSee('stuck 0, given up 1')->assertSee('Not run yet')->assertSee('Run all checks')->getContent();
        expect($html)->toContain('name="code" value="outbox_not_stuck"');
    });

    it('runs one check or all from the page, throttled, and only for those who may open it', function () {
        $as = fn () => $this->actingAs($this->viewer, 'admin');

        $as()->post('/admin/ops/integrity/run', ['code' => 'coupon_use_counts'])->assertRedirect('/admin/ops/integrity')->assertSessionHas('status');
        expect(DB::table('integrity_check_runs')->count())->toBe(1)->and(DB::table('audit_events')->where('action', 'ops.integrity_run')->count())->toBe(1);
        $as()->post('/admin/ops/integrity/run')->assertRedirect();
        expect(DB::table('integrity_check_runs')->count())->toBe(6);
        $as()->post('/admin/ops/integrity/run', ['code' => 'nope'])->assertSessionHasErrors('ops');
        $as()->post('/admin/ops/integrity/run', ['code' => 'bad code!'])->assertSessionHasErrors('code');

        $this->actingAs(catalogStaff(['queues.view']), 'admin')->post('/admin/ops/integrity/run')->assertForbidden();
        foreach (range(1, 5) as $i) {   // with the one above: six "run all" in the minute, all allowed (single checks have their own, wider limit)
            $as()->post('/admin/ops/integrity/run')->assertSessionHasNoErrors();
        }
        // the seventh "run all": past the limit (6 a minute): back to the page with a plain message, not an error page, and nothing ran
        $before = DB::table('audit_events')->where('action', 'ops.integrity_run')->count();
        $as()->post('/admin/ops/integrity/run')->assertRedirect('/admin/ops/integrity')->assertSessionHasErrors(['ops' => 'You ran the checks several times in the last minute. Wait a minute, then try again.']);
        expect(DB::table('audit_events')->where('action', 'ops.integrity_run')->count())->toBe($before);
    });

    it('lets staff run each check in turn without hitting the limit, and sends a reload of the run address back to the page', function () {
        $as = fn () => $this->actingAs($this->viewer, 'admin');

        // one check is one small read: clicking down the list (twice, even) is normal use (it was refused at the 7th click; seen on a dev site)
        foreach (['stock_ledger', 'stock_reservations', 'order_lines_add_up', 'refunds_within_captured', 'refunds_within_captured', 'outbox_not_stuck', 'coupon_use_counts', 'stock_ledger'] as $code) {
            $as()->post('/admin/ops/integrity/run', ['code' => $code])->assertRedirect('/admin/ops/integrity')->assertSessionHasNoErrors();
        }
        expect(DB::table('integrity_check_runs')->count())->toBe(6);

        $as()->get('/admin/ops/integrity/run')->assertRedirect('/admin/ops/integrity');   // reloading the address after a run, or a bookmark
        $this->actingAs(catalogStaff(['queues.view']), 'admin')->get('/admin/ops/integrity/run')->assertForbidden();
    });
});
