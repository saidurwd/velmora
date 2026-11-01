<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\RecordCodRemittance;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Shipping\Actions\ImportCourierStatement;
use App\Modules\Shipping\Actions\ResolveShipmentIssue;
use App\Modules\Shipping\Couriers\FakeCourier;
use App\Modules\Shipping\DTOs\CourierEvent;
use App\Modules\Shipping\Exceptions\ShipmentException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    shippingWorld($this);
    $this->refuses = fn (callable $call, string $reason) => expect($call)->toThrow(ShipmentException::class, $reason);
    $this->issues = fn (string $type) => DB::table('shipment_exceptions')->where('type', $type)->get();

    /** A cash order of one tee (৳730.00 to collect) or one mug (৳430.00), delivered by the fake courier a minute from now. */
    $this->delivered = function (int $variant) {
        $order = ($this->order)([$variant => 1]);
        $parcel = ($this->parcel)($order, [$variant => 1]);
        ($this->say)($parcel, 'DELIVERED', now()->addMinute()->format('Y-m-d H:i:s'));

        return DB::table('shipments')->where('id', $parcel->id)->first();
    };
    $this->csv = fn (array $rows) => "consignment_id,status,cod_amount,courier_fee,delivered_at\n".implode("\n", array_map(fn (array $r) => implode(',', $r), $rows));
    $this->import = fn (string $csv, int $received, string $ref = 'ST-1', ?CarbonImmutable $from = null, ?CarbonImmutable $to = null) => app(ImportCourierStatement::class)->handle($this->staff, 'fake', $ref, $csv, $from ?? now()->subDay()->toImmutable(), $to ?? now()->addMinutes(4)->toImmutable(), $received);
    $this->row = fn (object $parcel, string $status = 'DELIVERED', ?string $cod = null, string $fee = '60.00') => [$parcel->courier_consignment_id, $status, $cod ?? number_format($parcel->cod_amount_minor / 100, 2, '.', ''), $fee, '2026-11-02T10:00:00Z'];
});

describe('a statement that agrees', function () {
    it('matches every parcel, records the courier\'s payment against the cash we collected, and keeps what the courier charged', function () {
        $a = ($this->delivered)($this->tee);   // ৳730.00
        $b = ($this->delivered)($this->mug);   // ৳430.00

        $statement = ($this->import)(($this->csv)([($this->row)($a), ($this->row)($b)]), 104000);   // 1,160.00 collected, 120.00 kept by the courier, 1,040.00 paid

        $remittance = DB::table('cod_remittances')->first();
        expect($statement->line_count)->toBe(2)->and($statement->matched_count)->toBe(2)->and($statement->problem_count)->toBe(0)->and($statement->fee_minor)->toBe(12000)->and($statement->received_minor)->toBe(104000)
            ->and($remittance->status)->toBe('reconciled')->and($remittance->expected_minor)->toBe(116000)->and($remittance->amount_minor)->toBe(104000)->and($remittance->fee_minor)->toBe(12000)
            ->and(DB::table('courier_statement_lines')->where('statement_id', $statement->id)->pluck('result')->unique()->all())->toBe(['matched'])->and(DB::table('shipment_exceptions')->count())->toBe(0)
            ->and(DB::table('audit_events')->where('action', 'courier.statement_imported')->count())->toBe(1);
    });
});

describe('what does not agree', function () {
    it('flags a payment that is short, counting what the courier kept as part of what it owed', function () {
        $a = ($this->delivered)($this->tee);

        $statement = ($this->import)(($this->csv)([($this->row)($a, 'DELIVERED', null, '60.00')]), 60000);   // 730.00 collected, 60.00 kept, 600.00 paid: 70.00 missing

        $short = DB::table('reconciliation_exceptions')->where('type', 'cod_short')->get();
        expect(DB::table('cod_remittances')->value('status'))->toBe('short')->and($short)->toHaveCount(1)->and(json_decode($short[0]->details, true)['difference_minor'])->toBe(7000)->and($statement->problem_count)->toBe(0);
    });

    it('raises a parcel we do not know', function () {
        $a = ($this->delivered)($this->tee);

        $statement = ($this->import)(($this->csv)([($this->row)($a), ['FAKE-NOPE', 'DELIVERED', '500.00', '40.00', '2026-11-02T10:00:00Z']]), 67000);

        $issue = ($this->issues)('statement_unknown_parcel');
        expect($issue)->toHaveCount(1)->and($issue[0]->reference)->toBe('fake:ST-1:FAKE-NOPE')->and(DB::table('courier_statement_lines')->where('consignment_id', 'FAKE-NOPE')->value('result'))->toBe('unknown_parcel')->and($statement->matched_count)->toBe(1);
    });

    it('raises a parcel the courier says is delivered and we do not, and a parcel we show delivered that the courier does not', function () {
        $ours = ($this->delivered)($this->tee);
        $notYet = ($this->parcel)(($this->order)([$this->mug => 1]), [$this->mug => 1]);   // handed over, not delivered

        ($this->import)(($this->csv)([($this->row)($notYet, 'DELIVERED', '430.00'), ($this->row)($ours, 'IN_TRANSIT')]), 0);

        $mismatches = ($this->issues)('statement_status_mismatch');
        expect($mismatches)->toHaveCount(2)->and(DB::table('shipments')->where('id', $notYet->id)->value('status'))->toBe('handed_over')   // the statement never changes a parcel
            ->and(DB::table('shipments')->where('id', $ours->id)->value('status'))->toBe('delivered')->and(DB::table('cod_collections')->count())->toBe(1);
    });

    it('asks the courier itself when the statement says delivered, and follows the courier\'s own answer', function () {
        $order = ($this->order)([$this->mug => 1]);
        $parcel = ($this->parcel)($order, [$this->mug => 1]);
        app(FakeCourier::class)->events[(string) $parcel->courier_consignment_id] = [new CourierEvent((string) $parcel->courier_consignment_id, 'DELIVERED', CarbonImmutable::now('UTC')->addMinute())];

        $statement = ($this->import)(($this->csv)([($this->row)($parcel, 'DELIVERED', '430.00')]), 42000);   // 430.00 collected, 60.00 kept, 370.00 paid (statement says 420: see below)

        expect(DB::table('shipments')->where('id', $parcel->id)->value('status'))->toBe('delivered')->and($statement->matched_count)->toBe(1)->and(($this->issues)('statement_status_mismatch'))->toHaveCount(0)->and(DB::table('cod_collections')->count())->toBe(1);
    });

    it('raises a different cash amount', function () {
        $a = ($this->delivered)($this->tee);

        ($this->import)(($this->csv)([($this->row)($a, 'DELIVERED', '700.00')]), 64000);

        $issue = ($this->issues)('statement_cod_mismatch');
        expect($issue)->toHaveCount(1)->and(json_decode($issue[0]->details, true))->toMatchArray(['courier_cod_minor' => 70000, 'our_cod_minor' => 73000])->and(DB::table('courier_statement_lines')->value('result'))->toBe('cod_mismatch');
    });

    it('raises a parcel the courier delivered for cash in the period but left off the statement, and ignores one outside the period', function () {
        $listed = ($this->delivered)($this->tee);
        $left = ($this->delivered)($this->mug);

        ($this->import)(($this->csv)([($this->row)($listed)]), 67000);

        $issue = ($this->issues)('statement_missing_parcel');
        expect($issue)->toHaveCount(1)->and(json_decode($issue[0]->details, true)['shipment'])->toBe($left->public_id);

        $outside = ($this->delivered)($this->tee);
        ($this->import)(($this->csv)([($this->row)($outside)]), 67000, 'ST-2', now()->subDays(10)->toImmutable(), now()->subDays(5)->toImmutable());
        expect(($this->issues)('statement_missing_parcel'))->toHaveCount(1);   // the parcel delivered a minute from now is not in a period five days ago
    });

    it('does not count a parcel twice: one already covered by an earlier statement is raised and left out', function () {
        $a = ($this->delivered)($this->tee);
        $b = ($this->delivered)($this->mug);
        ($this->import)(($this->csv)([($this->row)($a)]), 67000, 'ST-1');

        $second = ($this->import)(($this->csv)([($this->row)($a), ($this->row)($b)]), 37000, 'ST-2');

        expect(($this->issues)('statement_already_remitted'))->toHaveCount(1)->and(DB::table('courier_statement_lines')->where('statement_id', $second->id)->where('result', 'already_remitted')->count())->toBe(1)
            ->and(DB::table('cod_remittance_items')->count())->toBe(2)->and(DB::table('cod_remittances')->orderByDesc('id')->value('expected_minor'))->toBe(43000);
    });

    it('raises money paid when nothing on the statement is waiting for it', function () {
        $a = ($this->delivered)($this->tee);
        ($this->import)(($this->csv)([($this->row)($a)]), 67000, 'ST-1');

        ($this->import)(($this->csv)([($this->row)($a, 'DELIVERED', null, '61.00')]), 5000, 'ST-2');   // another file (the courier charged a rupee more) for a parcel already paid for

        expect(($this->issues)('statement_money_mismatch'))->toHaveCount(1)->and(DB::table('cod_remittances')->count())->toBe(1);
    });
});

describe('the file and the statement', function () {
    it('refuses a malformed file as a whole and says which lines, storing nothing', function () {
        $a = ($this->delivered)($this->tee);
        $bad = ($this->csv)([($this->row)($a), ['X1', '', 'abc', '1.00', ''], ['X2', 'DELIVERED', '-5.00', '1.00', ''], ['X3', 'DELIVERED', '5.00', '1.00', 'not a date'], ['X4', 'DELIVERED', '1', '1', '', 'extra'], ($this->row)($a)]);

        expect(fn () => ($this->import)($bad, 0))->toThrow(ShipmentException::class, 'statement_invalid');
        try {
            ($this->import)($bad, 0);
        } catch (ShipmentException $e) {
            expect($e->params['errors'])->toContain('line 3')->and($e->params['errors'])->toContain('line 4')->and($e->params['errors'])->toContain('line 5')->and($e->params['errors'])->toContain('line 6')->and($e->params['errors'])->toContain('listed twice');
        }
        expect(DB::table('courier_statements')->count())->toBe(0)->and(DB::table('shipment_exceptions')->count())->toBe(0)->and(DB::table('cod_remittances')->count())->toBe(0);
    });

    it('refuses a wrong header, an empty file and a missing statement number', function () {
        ($this->refuses)(fn () => ($this->import)("a,b\n1,2", 0), 'statement_invalid');
        ($this->refuses)(fn () => ($this->import)("consignment_id,status,cod_amount,courier_fee,delivered_at\n", 0), 'statement_invalid');
        ($this->refuses)(fn () => ($this->import)(($this->csv)([['A', 'DELIVERED', '1', '1', '']]), 0, '  '), 'statement_invalid');
    });

    it('checks a file once, and a statement number once, and says so', function () {
        $a = ($this->delivered)($this->tee);
        $b = ($this->delivered)($this->mug);
        $csv = ($this->csv)([($this->row)($a)]);
        ($this->import)($csv, 67000, 'ST-1');

        ($this->refuses)(fn () => ($this->import)(str_replace("\n", "\r\n", $csv)."\r\n", 67000, 'ST-9'), 'statement_already_imported');   // the same file saved with Windows line ends
        ($this->refuses)(fn () => ($this->import)(($this->csv)([($this->row)($b)]), 37000, 'ST-1'), 'statement_already_imported');   // the same number for another file

        expect(DB::table('courier_statements')->count())->toBe(1);
    });

    it('refuses an unknown courier, a bad period and an oversized file', function () {
        $csv = ($this->csv)([['A', 'DELIVERED', '1', '1', '']]);
        ($this->refuses)(fn () => app(ImportCourierStatement::class)->handle($this->staff, 'nowhere', 'ST-1', $csv, now()->subDay()->toImmutable(), now()->toImmutable(), 0), 'unknown_courier');
        ($this->refuses)(fn () => ($this->import)($csv, 0, 'ST-1', now()->toImmutable(), now()->subDay()->toImmutable()), 'statement_window_invalid');
        ($this->refuses)(fn () => ($this->import)($csv, 0, 'ST-1', now()->subDays(40)->toImmutable(), now()->toImmutable()), 'statement_window_invalid');
        ($this->refuses)(fn () => ($this->import)($csv, 0, 'ST-1', now()->subDay()->toImmutable(), now()->addDay()->toImmutable()), 'statement_window_invalid');
        ($this->refuses)(fn () => ($this->import)(str_repeat('x', ImportCourierStatement::MAX_BYTES + 1), 0), 'statement_too_large');
    });

    it('keeps nothing when the cash side refuses (the statement number was already used for other parcels\' cash by hand)', function () {
        $a = ($this->delivered)($this->tee);
        $b = ($this->delivered)($this->mug);
        $other = DB::table('cod_collections')->where('shipment_id', $b->id)->value('public_id');
        app(RecordCodRemittance::class)->handle(new Actor(ActorType::Staff, $this->staff->id, null, 'x'), 'fake', 'ST-1', 43000, null, [['collection' => $other, 'amount_minor' => 43000]]);   // ST-1 was entered by hand for parcel B

        ($this->refuses)(fn () => ($this->import)(($this->csv)([($this->row)($a)]), 67000, 'ST-1'), 'statement_already_imported');

        expect(DB::table('courier_statements')->count())->toBe(0)->and(DB::table('shipment_exceptions')->count())->toBe(0)->and(DB::table('cod_remittances')->count())->toBe(1);
    });

    it('cannot be changed or deleted afterwards', function () {
        $a = ($this->delivered)($this->tee);
        $statement = ($this->import)(($this->csv)([($this->row)($a)]), 67000);

        expect(fn () => DB::table('courier_statements')->where('id', $statement->id)->update(['received_minor' => 1]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('courier_statements')->where('id', $statement->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('courier_statement_lines')->where('statement_id', $statement->id)->update(['result' => 'matched']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('courier_statement_lines')->where('statement_id', $statement->id)->delete())->toThrow(QueryException::class);
    });
});

describe('closing an item in the parcel queue', function () {
    it('resolves or ignores it with a note, once, and audits it', function () {
        $a = ($this->delivered)($this->tee);
        ($this->import)(($this->csv)([($this->row)($a, 'DELIVERED', '700.00'), ['FAKE-NOPE', 'DELIVERED', '1.00', '0', '']]), 0);
        [$one, $two] = DB::table('shipment_exceptions')->orderBy('id')->pluck('public_id')->all();

        $closed = app(ResolveShipmentIssue::class)->handle($this->staff, $one, 'resolved', 'Courier confirmed their typing error');
        $ignored = app(ResolveShipmentIssue::class)->handle($this->staff, $two, 'ignored', 'Not our parcel');

        expect($closed->status)->toBe('resolved')->and($closed->resolved_by)->toBe($this->staff->id)->and($closed->resolution)->toBe('Courier confirmed their typing error')->and($ignored->status)->toBe('ignored')->and(DB::table('audit_events')->whereIn('action', ['shipment.issue_resolved', 'shipment.issue_ignored'])->count())->toBe(2);
        ($this->refuses)(fn () => app(ResolveShipmentIssue::class)->handle($this->staff, $one, 'ignored', 'Again'), 'issue_not_open');
        ($this->refuses)(fn () => app(ResolveShipmentIssue::class)->handle($this->staff, $two, 'deleted', 'x'), 'issue_outcome_invalid');
        ($this->refuses)(fn () => app(ResolveShipmentIssue::class)->handle($this->staff, $two, 'resolved', '  '), 'note_required');
        ($this->refuses)(fn () => app(ResolveShipmentIssue::class)->handle($this->staff, '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'resolved', 'x'), 'issue_not_found');
    });
});
