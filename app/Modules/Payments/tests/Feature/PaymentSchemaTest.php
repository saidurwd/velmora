<?php

declare(strict_types=1);

use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Enums\TransactionStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The database is the last line of defence for money: these tests go straight at the tables and check that wrong amounts, rewrites,
 * deletes and impossible status changes are refused no matter what the application does.
 */

beforeEach(function () {
    fakeOrders([1, 2]);
    $next = 1000;
    $this->newOrder = function () use (&$next): int {   // each payment gets its own order: an order can have only one OPEN payment
        fakeOrders([++$next]);

        return $next;
    };
    $this->payment = fn (array $over = []) => DB::table('payments')->insertGetId($over + [
        'public_id' => strtolower((string) Str::ulid()), 'order_id' => ($this->newOrder)(), 'method_code' => 'online', 'provider' => 'fake', 'amount_minor' => 100000, 'currency' => 'BDT',
        'captured_minor' => 0, 'refunded_minor' => 0, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->tx = fn (int $paymentId, array $over = []) => DB::table('payment_transactions')->insertGetId($over + [
        'public_id' => strtolower((string) Str::ulid()), 'payment_id' => $paymentId, 'provider' => 'fake', 'merchant_ref' => 'VM-T-'.Str::random(10), 'attempt' => 1, 'status' => 'pending',
        'amount_minor' => 100000, 'currency' => 'BDT', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->refund = fn (int $paymentId, array $over = []) => DB::table('refunds')->insertGetId($over + [
        'public_id' => strtolower((string) Str::ulid()), 'payment_id' => $paymentId, 'order_id' => 1, 'amount_minor' => 5000, 'currency' => 'BDT', 'status' => 'requested', 'method' => 'gateway',
        'reason' => 'test', 'refund_ref' => substr('R'.Str::random(20), 0, 30), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->refuses = fn (callable $statement) => expect($statement)->toThrow(QueryException::class);
});

describe('payments', function () {
    it('accepts a normal payment and refuses impossible amounts', function () {
        expect(($this->payment)())->toBeInt();

        ($this->refuses)(fn () => ($this->payment)(['amount_minor' => 0]));
        ($this->refuses)(fn () => ($this->payment)(['amount_minor' => -5]));
        ($this->refuses)(fn () => ($this->payment)(['captured_minor' => 100001, 'paid_at' => now()]));      // more received than asked for
        ($this->refuses)(fn () => ($this->payment)(['captured_minor' => 5000, 'refunded_minor' => 5001, 'paid_at' => now()]));   // more given back than received
        ($this->refuses)(fn () => ($this->payment)(['captured_minor' => 5000]));                              // money received but no time recorded
        ($this->refuses)(fn () => ($this->payment)(['status' => 'settled']));
    });

    it('lets only the money-in/out and status columns change, never what was agreed', function () {
        $id = ($this->payment)();

        foreach (['amount_minor' => 1, 'order_id' => 2, 'provider' => 'other', 'method_code' => 'cod', 'currency' => 'USD', 'public_id' => strtolower((string) Str::ulid())] as $col => $value) {
            ($this->refuses)(fn () => DB::table('payments')->where('id', $id)->update([$col => $value]));
        }
        expect(DB::table('payments')->where('id', $id)->update(['status' => 'paid', 'captured_minor' => 100000, 'paid_at' => now(), 'updated_at' => now()]))->toBe(1);
        expect(DB::table('payments')->where('id', $id)->update(['refunded_minor' => 2500]))->toBe(1);
    });

    it('is never deleted', function () {
        $id = ($this->payment)();

        ($this->refuses)(fn () => DB::table('payments')->where('id', $id)->delete());
    });
});

describe('payment attempts', function () {
    it('has one reference per attempt, one bank transaction per attempt, and numbered attempts', function () {
        $p = ($this->payment)();
        ($this->tx)($p, ['merchant_ref' => 'VM-2610-000001-1', 'provider_txn_id' => 'BANK-1']);

        ($this->refuses)(fn () => ($this->tx)($p, ['merchant_ref' => 'VM-2610-000001-1', 'attempt' => 2]));                  // the same reference twice
        ($this->refuses)(fn () => ($this->tx)($p, ['merchant_ref' => 'VM-2610-000001-2', 'provider_txn_id' => 'BANK-1', 'attempt' => 2]));   // the same bank transaction twice
        ($this->refuses)(fn () => ($this->tx)($p, ['merchant_ref' => 'VM-2610-000001-3', 'attempt' => 1]));                  // attempt 1 twice
        ($this->refuses)(fn () => ($this->tx)($p, ['amount_minor' => 0, 'attempt' => 5]));
        expect(($this->tx)($p, ['attempt' => 2, 'provider_txn_id' => null]))->toBeInt()->and(($this->tx)($p, ['attempt' => 3, 'provider_txn_id' => null]))->toBeInt();   // many attempts may have no bank id yet
    });

    it('sets the bank transaction id once and then never changes it', function () {
        $t = ($this->tx)(($this->payment)());

        expect(DB::table('payment_transactions')->where('id', $t)->update(['provider_txn_id' => 'BANK-9', 'status' => 'succeeded', 'verified_at' => now()]))->toBe(1);
        ($this->refuses)(fn () => DB::table('payment_transactions')->where('id', $t)->update(['provider_txn_id' => 'BANK-10']));
        ($this->refuses)(fn () => DB::table('payment_transactions')->where('id', $t)->update(['provider_txn_id' => null]));
    });

    it('records what the gateway answered once, and never rewrites it', function () {
        $t = ($this->tx)(($this->payment)());

        expect(DB::table('payment_transactions')->where('id', $t)->update(['request_summary' => json_encode(['merchant_ref' => 'x'])]))->toBe(1);
        ($this->refuses)(fn () => DB::table('payment_transactions')->where('id', $t)->update(['request_summary' => json_encode(['merchant_ref' => 'changed'])]));
    });

    it('freezes the reference, amount and attempt number, and is never deleted', function () {
        $t = ($this->tx)(($this->payment)());

        foreach (['merchant_ref' => 'X', 'amount_minor' => 1, 'attempt' => 9, 'provider' => 'other', 'payment_id' => 99999] as $col => $value) {
            ($this->refuses)(fn () => DB::table('payment_transactions')->where('id', $t)->update([$col => $value]));
        }
        ($this->refuses)(fn () => DB::table('payment_transactions')->where('id', $t)->delete());
    });
});

describe('gateway notifications', function () {
    it('stores the same notification once, keeps the payload exactly as it came, and is never deleted', function () {
        $e = fn (array $over = []) => DB::table('payment_webhook_events')->insertGetId($over + ['provider' => 'fake', 'provider_event_id' => 'evt-1', 'payload' => json_encode(['status' => 'VALID']), 'status' => 'received', 'received_at' => now()]);
        $id = $e();

        ($this->refuses)(fn () => $e());
        expect($e(['provider_event_id' => 'evt-2']))->toBeInt()->and($e(['provider' => 'other', 'provider_event_id' => 'evt-1']))->toBeInt();
        ($this->refuses)(fn () => DB::table('payment_webhook_events')->where('id', $id)->update(['payload' => json_encode(['status' => 'FAILED'])]));
        ($this->refuses)(fn () => DB::table('payment_webhook_events')->where('id', $id)->update(['received_at' => now()->addDay()]));
        ($this->refuses)(fn () => DB::table('payment_webhook_events')->where('id', $id)->delete());
        ($this->refuses)(fn () => $e(['provider_event_id' => 'evt-3', 'status' => 'weird']));
        expect(DB::table('payment_webhook_events')->where('id', $id)->update(['status' => 'processed', 'verified' => true, 'processed_at' => now()]))->toBe(1);
    });
});

describe('refunds', function () {
    it('refuses a refund of nothing, an unknown way or status, and a repeated reference', function () {
        $p = ($this->payment)(['captured_minor' => 100000, 'paid_at' => now(), 'status' => 'paid']);
        ($this->refund)($p, ['refund_ref' => 'RF-1']);

        ($this->refuses)(fn () => ($this->refund)($p, ['amount_minor' => 0]));
        ($this->refuses)(fn () => ($this->refund)($p, ['method' => 'cheque']));
        ($this->refuses)(fn () => ($this->refund)($p, ['status' => 'done']));
        ($this->refuses)(fn () => ($this->refund)($p, ['refund_ref' => 'RF-1']));
    });

    it('cannot have its amount, payment or requester rewritten, or be deleted, but moves forward and gets a provider id once', function () {
        $p = ($this->payment)(['captured_minor' => 100000, 'paid_at' => now(), 'status' => 'paid']);
        $r = ($this->refund)($p);

        foreach (['amount_minor' => 1, 'payment_id' => ($this->payment)(), 'method' => 'manual', 'refund_ref' => 'OTHER', 'reason' => 'changed'] as $col => $value) {
            ($this->refuses)(fn () => DB::table('refunds')->where('id', $r)->update([$col => $value]));
        }
        ($this->refuses)(fn () => DB::table('refunds')->where('id', $r)->delete());

        expect(DB::table('refunds')->where('id', $r)->update(['status' => 'approved']))->toBe(1)
            ->and(DB::table('refunds')->where('id', $r)->update(['status' => 'processing', 'provider_refund_id' => 'PR-1']))->toBe(1);
        ($this->refuses)(fn () => DB::table('refunds')->where('id', $r)->update(['provider_refund_id' => 'PR-2']));
    });
});

describe('cash on delivery and reconciliation', function () {
    it('records cash collections that can never be changed, one per shipment and one by hand per order', function () {
        $c = fn (array $over = []) => DB::table('cod_collections')->insertGetId($over + ['public_id' => strtolower((string) Str::ulid()), 'order_id' => 1, 'amount_minor' => 50000, 'currency' => 'BDT', 'source' => 'manual', 'collected_at' => now(), 'created_at' => now()]);
        $id = $c(['shipment_id' => 7]);

        ($this->refuses)(fn () => $c(['shipment_id' => 7]));
        ($this->refuses)(fn () => $c(['amount_minor' => 0]));
        ($this->refuses)(fn () => $c(['source' => 'guess']));
        expect($c())->toBeInt()->and($c(['shipment_id' => 8]))->toBeInt();   // a hand-recorded collection has no shipment; each shipment collects its own cash
        ($this->refuses)(fn () => $c());   // but only one hand-recorded collection per order
        ($this->refuses)(fn () => DB::table('cod_collections')->where('id', $id)->update(['amount_minor' => 1]));
        ($this->refuses)(fn () => DB::table('cod_collections')->where('id', $id)->delete());
    });

    it('enters a courier statement once, and lists each collection on at most one remittance', function () {
        $col = DB::table('cod_collections')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'order_id' => 1, 'amount_minor' => 50000, 'currency' => 'BDT', 'source' => 'manual', 'collected_at' => now(), 'created_at' => now()]);
        $rem = fn (string $statement) => DB::table('cod_remittances')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'courier' => 'pathao', 'statement_ref' => $statement, 'amount_minor' => 50000, 'expected_minor' => 50000, 'currency' => 'BDT', 'status' => 'pending', 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $a = $rem('ST-1');

        ($this->refuses)(fn () => $rem('ST-1'));
        DB::table('cod_remittance_items')->insert(['remittance_id' => $a, 'cod_collection_id' => $col, 'amount_minor' => 50000]);
        ($this->refuses)(fn () => DB::table('cod_remittance_items')->insert(['remittance_id' => $rem('ST-2'), 'cod_collection_id' => $col, 'amount_minor' => 50000]));
    });

    it('raises the same problem once', function () {
        $x = fn (array $over = []) => DB::table('reconciliation_exceptions')->insertGetId($over + ['public_id' => strtolower((string) Str::ulid()), 'type' => 'amount_mismatch', 'reference' => 'BANK-1', 'details' => '{}', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $x();

        ($this->refuses)(fn () => $x());
        ($this->refuses)(fn () => $x(['type' => 'whatever', 'reference' => 'BANK-2']));
        expect($x(['type' => 'late_success']))->toBeInt();
    });
});

describe('the status rules in the database are the same as in the code', function () {
    // For every pair of statuses: put a row in the first status, try to move it to the second, and see whether the DATABASE allows it.
    // The answer must equal the enum's own rule. If someone changes one and not the other, this fails.
    it('agrees with PaymentState for every pair', function () {
        foreach (PaymentState::cases() as $from) {
            foreach (PaymentState::cases() as $to) {
                $id = ($this->payment)(['status' => $from->value]);
                $allowed = true;
                try {
                    DB::table('payments')->where('id', $id)->update(['status' => $to->value]);
                } catch (QueryException) {
                    $allowed = false;
                }
                expect($allowed)->toBe($from === $to || $from->canMoveTo($to), "payments {$from->value} → {$to->value}");
            }
        }
    });

    it('agrees with TransactionStatus for every pair', function () {
        $p = ($this->payment)();
        $n = 0;
        foreach (TransactionStatus::cases() as $from) {
            foreach (TransactionStatus::cases() as $to) {
                $id = ($this->tx)($p, ['status' => $from->value, 'attempt' => ++$n]);
                $allowed = true;
                try {
                    DB::table('payment_transactions')->where('id', $id)->update(['status' => $to->value]);
                } catch (QueryException) {
                    $allowed = false;
                }
                expect($allowed)->toBe($from === $to || $from->canMoveTo($to), "transaction {$from->value} → {$to->value}");
            }
        }
    });

    it('agrees with RefundStatus for every pair', function () {
        $p = ($this->payment)(['captured_minor' => 100000, 'paid_at' => now(), 'status' => 'paid']);
        foreach (RefundStatus::cases() as $from) {
            foreach (RefundStatus::cases() as $to) {
                $id = ($this->refund)($p, ['status' => $from->value]);
                $allowed = true;
                try {
                    DB::table('refunds')->where('id', $id)->update(['status' => $to->value]);
                } catch (QueryException) {
                    $allowed = false;
                }
                expect($allowed)->toBe($from === $to || $from->canMoveTo($to), "refund {$from->value} → {$to->value}");
            }
        }
    });
});
