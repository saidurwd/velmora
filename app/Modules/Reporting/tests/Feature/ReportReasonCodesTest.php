<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\CancelReason;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Reports\ReportCatalogue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Reason codes on cancellations and refunds, and the report built on them. The period is the Dhaka day 10 March 2026 (UTC 2026-03-09 18:00 included to 2026-03-10 18:00 excluded).
 *
 *   cancellations: customer_request x3 (100.00 + 200.00 + 50.00), out_of_stock x1 (10.00), one with no code (made before codes existed, 5.00), a USD one (left out),
 *                  one at the very first moment (in), one at the very last moment (out, next day), one the evening before (out).
 *   refunds      : item_returned x2 (30.00 + 20.00), item_damaged x1 (7.00), no code x1 (3.00), a requested (not yet gone through) one and a failed one (not counted), a USD one (left out).
 */

beforeEach(function () {
    shopWorld($this);
    reportWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->finance = catalogStaff(['reports.view', 'reports.export', 'reports.finance.view']);
    $this->analyst = catalogStaff(['reports.view', 'reports.export']);
    $this->f = new ReportFilter('2026-03-10', '2026-03-10');
    $this->rows = fn (?ReportFilter $f = null): array => iterator_to_array(app(ReportCatalogue::class)->find('refund_cancel_reasons')->rows($f ?? $this->f), false);

    $this->cancel = function (?string $code, int $total, string $at = '2026-03-10 05:00:00', string $currency = 'BDT'): int {
        $o = ($this->order)(['grand_total_minor' => $total, 'subtotal_minor' => $total - 100, 'shipping_minor' => 100, 'currency' => $currency, 'status' => 'cancelled', 'cancelled_at' => $at, 'cancel_reason' => 'words never shown']);
        DB::table('order_status_history')->insert(['order_id' => $o, 'axis' => 'status', 'from_state' => 'pending', 'to_state' => 'cancelled', 'actor_type' => 'staff', 'actor_id' => null,
            'reason' => 'words never shown', 'reason_code' => $code, 'created_at' => $at]);

        return $o;
    };
    $this->gone = function (?string $code, int $amount, string $status = 'succeeded', string $at = '2026-03-10 07:00:00', string $currency = 'BDT'): int {
        $o = ($this->order)(['grand_total_minor' => 100000, 'subtotal_minor' => 100000]);
        $p = ($this->pay)($o, 100000, '2026-03-09 05:00:00');

        return (int) DB::table('refunds')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()), 'payment_id' => $p, 'order_id' => $o, 'amount_minor' => $amount, 'currency' => $currency, 'status' => $status, 'method' => 'manual',
            'reason' => 'words never shown', 'reason_code' => $code, 'refund_ref' => 'RF-'.Str::random(12), 'created_at' => $at, 'updated_at' => $at,
        ]);
    };
});

describe('the report', function () {
    it('adds the period up by reason, most first, with what the orders and refunds came to', function () {
        ($this->cancel)('customer_request', 10000);
        ($this->cancel)('customer_request', 20000);
        ($this->cancel)('customer_request', 5000, '2026-03-09 18:00:00');   // the very first moment: in
        ($this->cancel)('out_of_stock', 1000);
        ($this->cancel)(null, 500);
        ($this->cancel)('customer_request', 99900, '2026-03-09 17:59:59.999999');   // the evening before: out
        ($this->cancel)('customer_request', 99900, '2026-03-10 18:00:00');   // the next day's first moment: out
        ($this->cancel)('out_of_stock', 99900, '2026-03-10 05:00:00', 'USD');   // another currency: out

        $other = ($this->order)(['status' => 'confirmed']);   // a move that is not a cancellation is not counted
        DB::table('order_status_history')->insert(['order_id' => $other, 'axis' => 'status', 'from_state' => 'pending', 'to_state' => 'confirmed', 'actor_type' => 'staff', 'created_at' => '2026-03-10 05:00:00']);

        ($this->gone)('item_returned', 3000);
        ($this->gone)('item_returned', 2000);
        ($this->gone)('item_returned', 111, 'succeeded', '2026-03-09 18:00:00');   // the very first moment: in
        ($this->gone)('item_returned', 99900, 'succeeded', '2026-03-10 18:00:00');   // the next day's first moment: out
        ($this->gone)('item_returned', 99900, 'succeeded', '2026-03-09 17:59:59.999999');   // the evening before: out
        ($this->gone)('item_damaged', 700);
        ($this->gone)(null, 300);
        ($this->gone)('item_returned', 88800, 'requested');   // not gone through yet
        ($this->gone)('item_returned', 88800, 'failed');
        ($this->gone)('item_damaged', 88800, 'succeeded', '2026-03-10 05:00:00', 'USD');

        expect(($this->rows)())->toBe([
            ['type' => 'Cancelled orders', 'reason' => 'Customer asked', 'count' => 3, 'value' => 35000, 'share' => 6000],
            ['type' => 'Cancelled orders', 'reason' => 'Out of stock', 'count' => 1, 'value' => 1000, 'share' => 2000],
            ['type' => 'Cancelled orders', 'reason' => 'Not recorded', 'count' => 1, 'value' => 500, 'share' => 2000],
            ['type' => 'Refunds', 'reason' => 'Item sent back', 'count' => 3, 'value' => 5111, 'share' => 6000],
            ['type' => 'Refunds', 'reason' => 'Item damaged', 'count' => 1, 'value' => 700, 'share' => 2000],
            ['type' => 'Refunds', 'reason' => 'Not recorded', 'count' => 1, 'value' => 300, 'share' => 2000],
        ]);
    });

    it('agrees with the ledgers: the counts and values add up to plain sums', function () {
        ($this->cancel)('customer_request', 10000);
        ($this->cancel)('other', 4000);
        ($this->cancel)(null, 600);
        ($this->gone)('wrong_item', 1500);
        ($this->gone)(null, 250);

        $rows = collect(($this->rows)());
        $cancelled = $rows->where('type', 'Cancelled orders');
        $refunds = $rows->where('type', 'Refunds');

        expect($cancelled->sum('count'))->toBe((int) DB::table('order_status_history')->where('to_state', 'cancelled')->count())
            ->and($cancelled->sum('value'))->toBe((int) DB::table('orders')->where('status', 'cancelled')->sum('grand_total_minor'))
            ->and($refunds->sum('count'))->toBe((int) DB::table('refunds')->where('status', 'succeeded')->count())
            ->and($refunds->sum('value'))->toBe((int) DB::table('refunds')->where('status', 'succeeded')->sum('amount_minor'));
    });

    it('is empty when nothing happened, and shows a code added later as it is', function () {
        expect(($this->rows)())->toBe([]);
    });

    it('is open to the people who may see reports, with the values only for finance, and in both languages', function () {
        ($this->cancel)('customer_request', 12345);

        $this->flushSession()->actingAs($this->analyst, 'admin')->get('/admin/reports/refund_cancel_reasons?from=2026-03-10&to=2026-03-10')->assertOk()->assertSee('Customer asked')->assertDontSee('123.45');
        $this->flushSession()->actingAs($this->finance, 'admin')->get('/admin/reports/refund_cancel_reasons?from=2026-03-10&to=2026-03-10')->assertOk()->assertSee('Customer asked')->assertSee('123.45');
        $this->flushSession()->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/reports/refund_cancel_reasons')->assertForbidden();

        app()->setLocale('bn');
        expect(__('reporting::catalogue.refund_cancel_reasons.title'))->toBe('রিফান্ড ও বাতিলের কারণ')->and(CancelReason::OutOfStock->label())->toBe('স্টক নেই');
    });
});

describe('recording a cancellation', function () {
    it('keeps the code with the history entry and in the audit, and refuses a cancellation without one', function () {
        $o = ($this->order)(['status' => 'pending']);
        $t = app(TransitionOrder::class);
        $staff = Actor::system('staff');

        expect(fn () => $t->status($staff, $o, OrderStatus::Cancelled, 'Called to cancel'))->toThrow(OrderException::class, 'reason_code_required');
        expect(DB::table('orders')->where('id', $o)->value('status'))->toBe('pending')->and(DB::table('order_status_history')->where('order_id', $o)->count())->toBe(0);

        $t->status($staff, $o, OrderStatus::Cancelled, 'Called to cancel', CancelReason::AddressProblem);
        $row = DB::table('order_status_history')->where('order_id', $o)->where('to_state', 'cancelled')->first();
        expect($row->reason_code)->toBe('address_problem')->and($row->reason)->toBe('Called to cancel');
        expect(json_decode((string) DB::table('audit_events')->where('action', 'order.status.changed')->orderByDesc('id')->value('after'), true))->toMatchArray(['reason_code' => 'address_problem']);
    });

    it('puts no code on a move that is not a cancellation', function () {
        $o = ($this->order)(['status' => 'pending']);
        app(TransitionOrder::class)->status(Actor::system('staff'), $o, OrderStatus::Confirmed, null, CancelReason::Other);

        expect(DB::table('order_status_history')->where('order_id', $o)->value('reason_code'))->toBeNull();
    });

    it('is taken from the staff form, which refuses a code that is not on the list', function () {
        $o = ($this->order)(['status' => 'pending']);
        $public = (string) DB::table('orders')->where('id', $o)->value('public_id');
        $staff = catalogStaff(['orders.view', 'orders.update', 'orders.cancel']);
        $url = "/admin/orders/{$public}/transition";

        $this->flushSession()->actingAs($staff, 'admin')->post($url, ['axis' => 'status', 'to' => 'cancelled', 'reason' => 'x', 'reason_code' => 'because'])->assertSessionHasErrors('reason_code');
        $this->flushSession()->actingAs($staff, 'admin')->post($url, ['axis' => 'status', 'to' => 'cancelled', 'reason' => 'x'])->assertSessionHasErrors('transition');
        expect(DB::table('orders')->where('id', $o)->value('status'))->toBe('pending');

        $this->flushSession()->actingAs($staff, 'admin')->post($url, ['axis' => 'status', 'to' => 'cancelled', 'reason' => 'x', 'reason_code' => 'out_of_stock'])->assertSessionHas('status');
        expect(DB::table('order_status_history')->where('order_id', $o)->value('reason_code'))->toBe('out_of_stock');
    });
});

describe('the database', function () {
    it('refuses a reason code that is not on the list, for refunds and for cancellations', function () {
        $o = ($this->order)();

        expect(fn () => ($this->gone)('because', 100))->toThrow(QueryException::class, 'refunds_reason_code_check')
            ->and(fn () => DB::table('order_status_history')->insert(['order_id' => $o, 'axis' => 'status', 'from_state' => 'pending', 'to_state' => 'cancelled', 'actor_type' => 'staff', 'reason_code' => 'because', 'created_at' => now()]))
            ->toThrow(QueryException::class, 'order_status_history_reason_code_check');
    });

    it('keeps a cancellation code to cancellation entries', function () {
        $o = ($this->order)();

        expect(fn () => DB::table('order_status_history')->insert(['order_id' => $o, 'axis' => 'status', 'from_state' => 'pending', 'to_state' => 'confirmed', 'actor_type' => 'staff', 'reason_code' => 'other', 'created_at' => now()]))
            ->toThrow(QueryException::class, 'order_status_history_reason_code_check')
            ->and(fn () => DB::table('order_status_history')->insert(['order_id' => $o, 'axis' => 'payment', 'from_state' => 'unpaid', 'to_state' => 'cancelled', 'actor_type' => 'staff', 'reason_code' => 'other', 'created_at' => now()]))
            ->toThrow(QueryException::class, 'order_status_history_reason_code_check');
    });

    it('never lets a recorded code change or an entry go', function () {
        $r = ($this->gone)('item_damaged', 100);
        $o = ($this->cancel)('other', 100);

        expect(fn () => DB::table('refunds')->where('id', $r)->update(['reason_code' => 'other']))->toThrow(QueryException::class, 'append-only')
            ->and(fn () => DB::table('order_status_history')->where('order_id', $o)->update(['reason_code' => 'out_of_stock']))->toThrow(QueryException::class, 'append-only')
            ->and(fn () => DB::table('order_status_history')->where('order_id', $o)->delete())->toThrow(QueryException::class, 'append-only');
        expect(DB::table('refunds')->where('id', $r)->value('reason_code'))->toBe('item_damaged');
    });

    it('still lets a refund move along its statuses', function () {
        $r = ($this->gone)('item_damaged', 100, 'requested');

        DB::table('refunds')->where('id', $r)->update(['status' => 'approved']);

        expect(DB::table('refunds')->where('id', $r)->value('status'))->toBe('approved')->and(DB::table('refunds')->where('id', $r)->value('reason_code'))->toBe('item_damaged');
    });
});
