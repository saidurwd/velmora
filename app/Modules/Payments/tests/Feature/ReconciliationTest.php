<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Orders\DTOs\OrderPaymentRequest;
use App\Modules\Payments\Actions\ImportSettlement;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Actions\ReconcilePendingPayments;
use App\Modules\Payments\Actions\ResolveReconciliationException;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Jobs\ReconcilePendingPaymentsJob;
use App\Modules\Payments\Models\SettlementImport;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Enums\ActorType;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class, RolesAndPermissionsSeeder::class]);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    $this->gateway = app(FakeGateway::class);
    $this->staff = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Amina');
    $this->other = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Babul');

    /** An online order of ৳1,500.00 whose payment was started (attempt 1, merchant ref VM-TEST-00000n-1). Returns the merchant ref. */
    $this->started = function (int $id, int $minor = 150000): string {
        fakeOrders([$id], ['payment_method_code' => 'online', 'subtotal_minor' => $minor, 'grand_total_minor' => $minor]);
        app(InitiatePayment::class)->handle(new OrderPaymentRequest($id, (string) DB::table('orders')->where('id', $id)->value('public_id'), sprintf('VM-TEST-%06d', $id), $minor, 'BDT', 'online', 'Rahim', '+8801712345678', null, 'House 5', 'Dhaka', '1205', 2, true, 'Tee', 'en'));

        return sprintf('VM-TEST-%06d-1', $id);
    };
    $this->answerPaid = fn (string $ref, int $minor = 150000, ?string $bank = null) => $this->gateway->answer($ref, new VerifiedPayment(VerifiedStatus::Valid, $ref, Money::of($minor), $bank ?? 'BANK-'.$ref, 'VAL-'.$ref));
    $this->paid = function (int $id, int $minor = 150000): string {
        $ref = ($this->started)($id, $minor);
        ($this->answerPaid)($ref, $minor);
        app(VerifyPayment::class)->handle($ref);

        return $ref;
    };
    $this->import = fn (string $csv, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, ?Actor $by = null) => app(ImportSettlement::class)->handle($by ?? $this->staff, 'fake', $csv, $from ?? now()->subDay()->toImmutable(), $to ?? now()->addMinute()->toImmutable());
    $this->csv = fn (array $rows) => "reference,bank_txn_id,amount,currency,settled_at\n".implode("\n", array_map(fn (array $r) => implode(',', $r), $rows));
    $this->open = fn (string $type) => DB::table('reconciliation_exceptions')->where('type', $type)->where('status', 'open')->get();
    $this->refuses = fn (callable $call, string $reason) => expect($call)->toThrow(PaymentException::class, $reason);
});

describe('the scheduled check of unfinished payments', function () {
    it('finds a payment whose notification never arrived, but only after a few minutes', function () {
        $ref = ($this->started)(1);
        ($this->answerPaid)($ref);

        $early = app(ReconcilePendingPayments::class)->handle();
        expect($early['checked'])->toBe(0)->and(DB::table('payments')->value('status'))->toBe('pending');

        $this->travel(11)->minutes();
        $late = app(ReconcilePendingPayments::class)->handle();

        expect($late['checked'])->toBe(1)->and($late['paid'])->toBe(1)->and(DB::table('payments')->value('status'))->toBe('paid')->and(DB::table('orders')->value('payment_status'))->toBe('paid');
    });

    it('leaves an attempt alone when the gateway has no answer, and asks again next time', function () {
        ($this->started)(1);
        $this->travel(11)->minutes();

        $first = app(ReconcilePendingPayments::class)->handle();
        $second = app(ReconcilePendingPayments::class)->handle();

        expect($first['paid'])->toBe(0)->and($second['checked'])->toBe(1)->and(DB::table('payments')->value('status'))->toBe('pending');
    });

    it('counts a gateway that cannot be reached and changes nothing', function () {
        $ref = ($this->started)(1);
        $this->gateway->failNext('verify');
        $this->travel(11)->minutes();

        $result = app(ReconcilePendingPayments::class)->handle();

        expect($result['unreachable'])->toBe(1)->and(DB::table('payments')->value('status'))->toBe('pending');
    });

    it('does not touch finished attempts, and is safe to run twice', function () {
        ($this->paid)(1);
        $this->travel(11)->minutes();

        $a = app(ReconcilePendingPayments::class)->handle();
        $b = app(ReconcilePendingPayments::class)->handle();

        expect($a['checked'])->toBe(0)->and($b['checked'])->toBe(0)->and(DB::table('outbox_messages')->where('event_name', 'payments.succeeded')->count())->toBe(1);
    });

    it('raises a payment that stays open long after its time limit to finance, once', function () {
        ($this->started)(1);
        $this->travel(30 + 121)->minutes();   // past the 30-minute limit and 2 hours more

        app(ReconcilePendingPayments::class)->handle();
        $again = app(ReconcilePendingPayments::class)->handle();

        $e = ($this->open)('long_pending');
        expect($e)->toHaveCount(1)->and($again['long_pending'])->toBe(1)->and($e[0]->reference)->toBe(DB::table('payments')->value('public_id'));
    });

    it('is scheduled every ten minutes', function () {
        $events = collect(Schedule::events())->filter(fn ($e) => str_contains((string) $e->description, 'payments:reconcile-pending'));

        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('*/10 * * * *');
        dispatch_sync(new ReconcilePendingPaymentsJob);
    });
});

describe('importing a settlement file', function () {
    it('accepts a file that agrees with our records', function () {
        $a = ($this->paid)(1);
        $b = ($this->paid)(2, 80000);

        $import = ($this->import)(($this->csv)([[$a, 'BANK-'.$a, '1500.00', 'BDT', '2026-10-31T10:00:00Z'], [$b, 'BANK-'.$b, '800.00', 'BDT', '2026-10-31T10:05:00Z']]));

        expect($import->row_count)->toBe(2)->and($import->matched_count)->toBe(2)->and($import->problem_count)->toBe(0)->and(DB::table('reconciliation_exceptions')->count())->toBe(0)->and(DB::table('audit_events')->where('action', 'settlement.imported')->count())->toBe(1);
    });

    it('matches by our reference alone or by the bank id alone', function () {
        $a = ($this->paid)(1);
        $b = ($this->paid)(2);

        $import = ($this->import)(($this->csv)([[$a, '', '1500.00', 'BDT', '2026-10-31T10:00:00Z'], ['', 'BANK-'.$b, '1500.00', 'BDT', '2026-10-31T10:00:00Z']]));

        expect($import->matched_count)->toBe(2)->and($import->problem_count)->toBe(0);
    });

    it('raises a line we do not know', function () {
        ($this->paid)(1);

        $import = ($this->import)(($this->csv)([['VM-NOPE-1', 'BANK-GHOST', '500.00', 'BDT', '2026-10-31T10:00:00Z'], ['VM-TEST-000001-1', 'BANK-VM-TEST-000001-1', '1500.00', 'BDT', '2026-10-31T10:00:00Z']]));

        $e = ($this->open)('unmatched_provider_txn');
        expect($e)->toHaveCount(1)->and($e[0]->reference)->toBe('BANK-GHOST')->and(json_decode($e[0]->details, true)['reason'])->toBe('not_ours')->and($import->problem_count)->toBe(1)->and($import->matched_count)->toBe(1);
    });

    it('raises a different amount, a different currency and a different bank id, each once', function () {
        $a = ($this->paid)(1);
        $b = ($this->paid)(2);
        $c = ($this->paid)(3);

        ($this->import)(($this->csv)([[$a, 'BANK-'.$a, '1499.99', 'BDT', '2026-10-31T10:00:00Z'], [$b, 'BANK-'.$b, '1500.00', 'USD', '2026-10-31T10:00:00Z'], [$c, 'BANK-OTHER', '1500.00', 'BDT', '2026-10-31T10:00:00Z']]));

        $amount = ($this->open)('amount_mismatch');
        expect($amount)->toHaveCount(1)->and(json_decode($amount[0]->details, true)['our_amount_minor'])->toBe(150000)->and(($this->open)('currency_mismatch'))->toHaveCount(1)
            ->and(json_decode(($this->open)('unmatched_provider_txn')[0]->details, true)['reason'])->toBe('bank_id_differs');
    });

    it('raises a payment of ours inside the period that the file does not list', function () {
        $a = ($this->paid)(1);
        $b = ($this->paid)(2);

        $import = ($this->import)(($this->csv)([[$a, 'BANK-'.$a, '1500.00', 'BDT', '2026-10-31T10:00:00Z']]));

        $e = ($this->open)('orphan_payment');
        expect($e)->toHaveCount(1)->and($e[0]->reference)->toBe($b)->and($import->problem_count)->toBe(1);
    });

    it('does not raise our payments that fall outside the period', function () {
        $a = ($this->paid)(1);
        $b = ($this->paid)(2);

        ($this->import)(($this->csv)([[$a, 'BANK-'.$a, '1500.00', 'BDT', '2026-10-31T10:00:00Z']]), now()->subDays(10)->toImmutable(), now()->subDays(5)->toImmutable());

        expect(($this->open)('orphan_payment'))->toHaveCount(0)->and($b)->not->toBeEmpty();
    });

    it('never believes the file: a line that says paid is put to the gateway, and only its answer counts', function () {
        $ref = ($this->started)(1);   // we show it pending; the gateway has not told us anything
        $line = ($this->csv)([[$ref, 'BANK-X', '1500.00', 'BDT', '2026-10-31T10:00:00Z']]);

        ($this->import)($line);

        $e = ($this->open)('unmatched_provider_txn');
        expect(DB::table('payments')->value('status'))->toBe('pending')->and($e)->toHaveCount(1)->and(json_decode($e[0]->details, true)['reason'])->toBe('file_says_paid_we_do_not');
    });

    it('lets the gateway settle a payment the file says is paid, which then needs no exception', function () {
        $ref = ($this->started)(1);
        ($this->answerPaid)($ref, 150000, 'BANK-LATE');

        $import = ($this->import)(($this->csv)([[$ref, 'BANK-LATE', '1500.00', 'BDT', '2026-10-31T10:00:00Z']]));

        expect(DB::table('payments')->value('status'))->toBe('paid')->and($import->matched_count)->toBe(1)->and($import->problem_count)->toBe(0);
    });

    it('refuses a malformed file as a whole and says which lines', function () {
        $a = ($this->paid)(1);
        $bad = ($this->csv)([[$a, 'BANK-'.$a, '1500.00', 'BDT', '2026-10-31T10:00:00Z'], [$a, '', 'abc', 'BDT', '2026-10-31T10:00:00Z'], ['', '', '5.00', 'BDT', '2026-10-31T10:00:00Z'], ['x', 'y', '5.005', 'BDT', 'not a date'], ['x', 'y', '5.00', 'TAKA', '2026-10-31T10:00:00Z']]);

        expect(fn () => ($this->import)($bad))->toThrow(PaymentException::class, 'settlement_file_invalid');

        try {
            ($this->import)($bad);
        } catch (PaymentException $e) {
            expect($e->params['errors'])->toContain('line 3')->and($e->params['errors'])->toContain('line 4')->and($e->params['errors'])->toContain('line 5')->and($e->params['errors'])->toContain('line 6');
        }
        expect(SettlementImport::query()->count())->toBe(0)->and(DB::table('reconciliation_exceptions')->count())->toBe(0);
    });

    it('refuses a wrong header, an empty file, a line listed twice, zero amounts and too many columns', function () {
        ($this->refuses)(fn () => ($this->import)("ref,amount\n1,2"), 'settlement_file_invalid');
        ($this->refuses)(fn () => ($this->import)("reference,bank_txn_id,amount,currency,settled_at\n"), 'settlement_file_invalid');
        ($this->refuses)(fn () => ($this->import)(($this->csv)([['A', 'B', '5.00', 'BDT', '2026-10-31T10:00:00Z'], ['A2', 'B', '5.00', 'BDT', '2026-10-31T10:00:00Z']])), 'settlement_file_invalid');
        ($this->refuses)(fn () => ($this->import)(($this->csv)([['A', 'B', '0.00', 'BDT', '2026-10-31T10:00:00Z']])), 'settlement_file_invalid');
        ($this->refuses)(fn () => ($this->import)(($this->csv)([['A', 'B', '5.00', 'BDT', '2026-10-31T10:00:00Z', 'extra']])), 'settlement_file_invalid');
    });

    it('accepts a file from a spreadsheet: a byte-order mark, Windows line ends and a capitalised header', function () {
        $a = ($this->paid)(1);
        $csv = "\xEF\xBB\xBFReference,Bank_Txn_Id,Amount,Currency,Settled_At\r\n{$a},BANK-{$a},1500.00,bdt,2026-10-31T10:00:00Z\r\n";

        $import = ($this->import)($csv);

        expect($import->matched_count)->toBe(1);
    });

    it('refuses the same file twice, even when it is saved with different line endings', function () {
        $a = ($this->paid)(1);
        $csv = ($this->csv)([[$a, 'BANK-'.$a, '1500.00', 'BDT', '2026-10-31T10:00:00Z']]);
        ($this->import)($csv);

        ($this->refuses)(fn () => ($this->import)(str_replace("\n", "\r\n", $csv)."\r\n"), 'settlement_already_imported');

        expect(SettlementImport::query()->count())->toBe(1);
    });

    it('refuses an unknown provider, a bad period and an oversized file', function () {
        $csv = ($this->csv)([['A', 'B', '5.00', 'BDT', '2026-10-31T10:00:00Z']]);
        ($this->refuses)(fn () => app(ImportSettlement::class)->handle($this->staff, 'nope', $csv, now()->subDay()->toImmutable(), now()->toImmutable()), 'unknown_provider');
        ($this->refuses)(fn () => ($this->import)($csv, now()->toImmutable(), now()->subDay()->toImmutable()), 'settlement_window_invalid');
        ($this->refuses)(fn () => ($this->import)($csv, now()->subDays(40)->toImmutable(), now()->toImmutable()), 'settlement_window_invalid');
        ($this->refuses)(fn () => ($this->import)($csv, now()->subDay()->toImmutable(), now()->addDay()->toImmutable()), 'settlement_window_invalid');
        ($this->refuses)(fn () => ($this->import)(str_repeat('x', ImportSettlement::MAX_BYTES + 1)), 'settlement_file_too_large');
    });

    it('raises the same problem once even when a corrected file is imported later', function () {
        $a = ($this->paid)(1);
        ($this->import)(($this->csv)([[$a, 'BANK-'.$a, '1499.00', 'BDT', '2026-10-31T10:00:00Z']]));
        ($this->import)(($this->csv)([[$a, 'BANK-'.$a, '1499.00', 'BDT', '2026-10-31T10:00:00Z'], ['Z-9', 'Z-9', '1.00', 'BDT', '2026-10-31T10:00:00Z']]));

        expect(($this->open)('amount_mismatch'))->toHaveCount(1)->and(SettlementImport::query()->count())->toBe(2);
    });

    it('cannot be changed or deleted afterwards', function () {
        $a = ($this->paid)(1);
        $import = ($this->import)(($this->csv)([[$a, 'BANK-'.$a, '1500.00', 'BDT', '2026-10-31T10:00:00Z']]));

        expect(fn () => DB::table('settlement_imports')->where('id', $import->id)->update(['problem_count' => 0]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('settlement_imports')->where('id', $import->id)->delete())->toThrow(QueryException::class);
    });
});

describe('closing an exception', function () {
    beforeEach(function () {
        $a = ($this->paid)(1);
        ($this->import)(($this->csv)([[$a, 'BANK-'.$a, '1.00', 'BDT', '2026-10-31T10:00:00Z']]));
        $this->exception = DB::table('reconciliation_exceptions')->first();
    });

    it('resolves it with a note, who and when, and audits it', function () {
        $row = app(ResolveReconciliationException::class)->handle($this->staff, $this->exception->public_id, 'resolved', 'Provider confirmed a keying error on their side');

        expect($row->status)->toBe('resolved')->and($row->resolved_by)->toBe($this->staff->id)->and($row->resolved_at)->not->toBeNull()->and($row->resolution)->toBe('Provider confirmed a keying error on their side')
            ->and(DB::table('audit_events')->where('action', 'reconciliation.resolved')->count())->toBe(1);
    });

    it('can ignore it', function () {
        $row = app(ResolveReconciliationException::class)->handle($this->staff, $this->exception->public_id, 'ignored', 'Test transaction');

        expect($row->status)->toBe('ignored');
    });

    it('needs a note and a valid outcome, and cannot be closed twice', function () {
        $close = fn (string $outcome, string $note, ?Actor $by = null) => app(ResolveReconciliationException::class)->handle($by ?? $this->staff, $this->exception->public_id, $outcome, $note);
        ($this->refuses)(fn () => $close('resolved', '   '), 'exception_note_required');
        ($this->refuses)(fn () => $close('deleted', 'x'), 'exception_outcome_invalid');
        ($this->refuses)(fn () => app(ResolveReconciliationException::class)->handle($this->staff, '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'resolved', 'x'), 'exception_not_found');
        $close('resolved', 'Fixed');

        ($this->refuses)(fn () => $close('ignored', 'Second thoughts', $this->other), 'exception_not_open');

        expect(DB::table('reconciliation_exceptions')->where('id', $this->exception->id)->value('status'))->toBe('resolved')->and((int) DB::table('reconciliation_exceptions')->where('id', $this->exception->id)->value('resolved_by'))->toBe($this->staff->id);
    });

    it('moves no money', function () {
        $before = [DB::table('payments')->sum('captured_minor'), DB::table('payments')->sum('refunded_minor'), DB::table('outbox_messages')->count()];

        app(ResolveReconciliationException::class)->handle($this->staff, $this->exception->public_id, 'resolved', 'Done');

        expect([DB::table('payments')->sum('captured_minor'), DB::table('payments')->sum('refunded_minor'), DB::table('outbox_messages')->count()])->toEqual($before);
    });
});
