<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Reports\ReportCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Delivery performance, returns by reason and coupon performance, against scenarios worked out by hand. Rows are written straight into the ledger tables. The period is the Dhaka
 * day 10 March 2026, which in UTC runs from 2026-03-09 18:00:00 (included) to 2026-03-10 18:00:00 (excluded).
 *
 *   steadfast: 7 parcels in the period: delivered in 12h, in 24h and (USD, so its cash is not added) in 1h; one failed after 2 attempts; two on the way; one brought back.
 *              Not counted: a parcel still being prepared, a cancelled one (even if it was handed over), one handed over the evening before (Dhaka).   cash to collect 1,100.00
 *   pathao   : 3 parcels: one delivered in 1.5 hours, one handed over at the very first moment of the day, one of unknown state (both on the way). A parcel handed over at the very last moment is next day's.
 *   returns  : customer/damaged 7 requests (units 10: 3 approved, 2 rejected, 2 goods received, 1 cancelled), customer/changed_mind 1 (units 4), customer/other 1 (at the very first moment), origin/undelivered 1 (units 2),
 *              and one request on the next day.
 *   coupons  : SAVE10 on two live orders (100.00 + 50.00 goods, 100.00 + 50.00 off), and on a cancelled order and a USD order (both left out), and on the next day.
 *              EID on two orders (1,000.00 and 333.33 goods, 20.00 and 10.01 off).
 */

beforeEach(function () {
    shopWorld($this);
    reportWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->finance = catalogStaff(['reports.view', 'reports.export', 'reports.finance.view']);
    $this->analyst = catalogStaff(['reports.view', 'reports.export']);
    $this->as = fn (User $u) => $this->flushSession()->actingAs($u, 'admin');
    $this->f = new ReportFilter('2026-03-10', '2026-03-10');
    $this->rowsOf = fn (string $code, ?ReportFilter $f = null): array => iterator_to_array(app(ReportCatalogue::class)->find($code)->rows($f ?? $this->f), false);

    $this->accounts = [];
    foreach (['steadfast', 'pathao'] as $courier) {
        $this->accounts[$courier] = DB::table('courier_accounts')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'courier' => $courier, 'label' => ucfirst($courier), 'is_active' => 1,
            'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    /** A parcel, straight into the ledger table. */
    $this->parcel = function (string $courier, string $status, ?string $handed, ?string $delivered = null, int $cod = 0, int $attempts = 0, string $currency = 'BDT'): int {
        return (int) DB::table('shipments')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()), 'order_id' => ($this->order)(), 'courier_account_id' => $this->accounts[$courier], 'courier' => $courier, 'status' => $status,
            'cod_amount_minor' => $cod, 'currency' => $currency, 'attempts' => $attempts, 'handed_over_at' => $handed, 'delivered_at' => $delivered, 'created_at' => '2026-03-08 00:00:00',
            'updated_at' => '2026-03-08 00:00:00',
        ]);
    };
    /** A return request with one line of the given quantity. */
    $this->orderItem = null;
    $this->ret = function (string $kind, string $reason, string $status, int $units, string $at = '2026-03-10 05:00:00', ?int $shipment = null): int {
        $this->orderItem ??= (function (): int {
            $o = ($this->order)();

            return (int) DB::table('order_items')->insertGetId([
                'order_id' => $o, 'variant_id' => $this->tee['variantId'], 'product_type' => 'physical', 'name_snapshot' => json_encode(['en' => 'x']), 'sku_snapshot' => 'RET-1', 'quantity' => 50,
                'unit_price_minor' => 100, 'discount_minor' => 0, 'tax_rate_bp' => 0, 'tax_minor' => 0, 'line_total_minor' => 5000, 'fulfilled_qty' => 0, 'returned_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        })();
        $done = in_array($status, ['received', 'closed'], true);
        $id = (int) DB::table('return_requests')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()), 'order_id' => ($this->order)(), 'shipment_id' => $shipment, 'kind' => $kind, 'status' => $status, 'reason_code' => $reason,
            'reason' => 'my own words, never shown', 'requested_by_type' => 'customer', 'refund_suggested_minor' => $status === 'closed' ? 0 : null, 'received_at' => $done ? $at : null,
            'closed_at' => $status === 'closed' ? $at : null, 'created_at' => $at, 'updated_at' => $at,
        ]);
        if ($units > 0) {
            DB::table('return_items')->insert(['return_id' => $id, 'order_item_id' => $this->orderItem, 'quantity' => $units, 'created_at' => $at]);
        }

        return $id;
    };
    $this->coupon = fn (string $code): int => (int) DB::table('coupons')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'code' => $code, 'type' => 'fixed', 'value' => 100,
        'created_at' => now(), 'updated_at' => now()]);
    $this->use = fn (int $coupon, int $order, int $amount, string $at = '2026-03-10 07:00:00') => DB::table('coupon_redemptions')->insert(['coupon_id' => $coupon, 'order_id' => $order,
        'redeemer' => 'x', 'amount_minor' => $amount, 'redeemed_at' => $at]);
});

describe('delivery performance', function () {
    beforeEach(function () {
        $p = $this->parcel;
        $p('steadfast', 'delivered', '2026-03-10 06:00:00', '2026-03-10 18:00:00', 50000);
        $p('steadfast', 'delivered', '2026-03-10 07:00:00', '2026-03-11 07:00:00', 0, 1);
        $p('steadfast', 'failed', '2026-03-10 08:00:00', null, 30000, 2);
        $p('steadfast', 'in_transit', '2026-03-10 09:00:00', null, 10000);
        $p('steadfast', 'returned', '2026-03-10 10:00:00', null, 20000);
        $p('steadfast', 'delivered', '2026-03-10 10:00:00', '2026-03-10 11:00:00', 99999, 0, 'USD');
        $p('steadfast', 'created', null, null, 777);
        $p('steadfast', 'cancelled', '2026-03-10 06:30:00', null, 888);
        $p('steadfast', 'out_for_delivery', '2026-03-10 09:30:00');
        $p('pathao', 'unknown', '2026-03-10 07:00:00');
        $p('steadfast', 'delivered', '2026-03-09 17:00:00', '2026-03-09 18:00:00', 555);
        $p('pathao', 'delivered', '2026-03-10 05:00:00', '2026-03-10 06:30:00', 5000);
        $p('pathao', 'handed_over', '2026-03-09 18:00:00', null, 0);
        $p('pathao', 'handed_over', '2026-03-10 18:00:00', null, 4444);
    });

    it('adds up each courier, leaving out parcels not handed over and those outside the day', function () {
        $rows = ($this->rowsOf)('delivery_performance');

        expect($rows)->toBe([
            ['courier' => 'Pathao', 'parcels' => 3, 'delivered' => 1, 'failed' => 0, 'returned' => 0, 'in_transit' => 2, 'delivery_rate' => 3333, 'return_rate' => 0, 'failed_attempts' => 0, 'avg_hours' => 2, 'cod_value' => 5000],
            ['courier' => 'Steadfast', 'parcels' => 7, 'delivered' => 3, 'failed' => 1, 'returned' => 1, 'in_transit' => 2, 'delivery_rate' => 4286, 'return_rate' => 1429, 'failed_attempts' => 3, 'avg_hours' => 12,
                'cod_value' => 110000],
        ]);
    });

    it('agrees with plain sums taken from the shipments table', function () {
        $rows = ($this->rowsOf)('delivery_performance');
        $plain = DB::table('shipments')->whereNotIn('status', ['created', 'cancelled'])->where('handed_over_at', '>=', '2026-03-09 18:00:00')->where('handed_over_at', '<', '2026-03-10 18:00:00')->count();

        expect(array_sum(array_column($rows, 'parcels')))->toBe($plain)
            ->and(array_sum(array_column($rows, 'delivered')) + array_sum(array_column($rows, 'failed')) + array_sum(array_column($rows, 'returned')) + array_sum(array_column($rows, 'in_transit')))->toBe($plain);
    });

    it('never divides by zero and is empty when nothing was handed over', function () {
        expect(($this->rowsOf)('delivery_performance', new ReportFilter('2026-03-15', '2026-03-15')))->toBe([]);
        $this->parcel->__invoke('pathao', 'handed_over', '2026-03-15 06:00:00');
        $row = ($this->rowsOf)('delivery_performance', new ReportFilter('2026-03-15', '2026-03-15'))[0];

        expect($row)->toMatchArray(['parcels' => 1, 'delivered' => 0, 'delivery_rate' => 0, 'return_rate' => 0, 'avg_hours' => 0]);
    });

    it('keeps the cash column for finance and shows the rest to everyone with reports', function () {
        $analyst = ($this->as)($this->analyst)->get('/admin/reports/delivery_performance?from=2026-03-10&to=2026-03-10')->assertOk();
        $finance = ($this->as)($this->finance)->get('/admin/reports/delivery_performance?from=2026-03-10&to=2026-03-10')->assertOk();

        $analyst->assertSee('Steadfast')->assertSee('42.86%')->assertSee('14.29%')->assertDontSee('Cash to collect')->assertDontSee('1,100.00');
        $finance->assertSee('Steadfast')->assertSee('Cash to collect')->assertSee('1,100.00');
    });

    it('writes the same rows into a file as it shows', function () {
        Storage::fake('private');
        config(['reporting.exports.disk' => 'private']);
        ($this->as)($this->finance)->post('/admin/reports/delivery_performance/export', ['from' => '2026-03-10', 'to' => '2026-03-10'])->assertRedirect();
        $e = DB::table('report_exports')->first();
        $lines = preg_split('/\r?\n/', trim(str_replace("\xEF\xBB\xBF", '', (string) Storage::disk('private')->get((string) $e->file_key))));

        expect($lines[0])->toBe('Courier,Parcels,Delivered,Failed,"Brought back","Still on the way","Delivered share","Brought-back share","Failed attempts","Average hours to deliver","Cash to collect"')
            ->and($lines[2])->toBe('Steadfast,7,3,1,1,2,42.86,14.29,3,12,1100.00');
    });
});

describe('returns by reason', function () {
    beforeEach(function () {
        $r = $this->ret;
        $r('customer', 'damaged', 'requested', 1);
        $r('customer', 'damaged', 'authorized', 2);
        $r('customer', 'damaged', 'rejected', 1);
        $r('customer', 'damaged', 'rejected', 1);
        $r('customer', 'damaged', 'received', 3);
        $r('customer', 'damaged', 'closed', 1);
        $r('customer', 'damaged', 'cancelled', 1);
        $r('customer', 'changed_mind', 'requested', 4);
        $r('origin', 'undelivered', 'requested', 2, '2026-03-10 05:00:00', ($this->parcel)('steadfast', 'returned', '2026-03-09 20:00:00'));
        $r('customer', 'damaged', 'requested', 7, '2026-03-10 18:00:00');
        $r('customer', 'other', 'requested', 1, '2026-03-09 18:00:00');
    });

    it('adds up the requests by who started them and why, most requests first', function () {
        expect(($this->rowsOf)('returns_by_reason'))->toBe([
            ['kind' => 'Customer', 'reason' => 'Damaged', 'requests' => 7, 'units' => 10, 'approved' => 3, 'rejected' => 2, 'received' => 2, 'share' => 7000],
            ['kind' => 'Customer', 'reason' => 'Changed their mind', 'requests' => 1, 'units' => 4, 'approved' => 0, 'rejected' => 0, 'received' => 0, 'share' => 1000],
            ['kind' => 'Customer', 'reason' => 'Other', 'requests' => 1, 'units' => 1, 'approved' => 0, 'rejected' => 0, 'received' => 0, 'share' => 1000],
            ['kind' => 'Courier brought it back', 'reason' => 'Not delivered', 'requests' => 1, 'units' => 2, 'approved' => 0, 'rejected' => 0, 'received' => 0, 'share' => 1000],
        ]);
    });

    it('agrees with plain sums and counts a request with several lines once', function () {
        $id = DB::table('return_requests')->where('reason_code', 'changed_mind')->value('id');
        $item2 = DB::table('order_items')->insertGetId(['order_id' => DB::table('order_items')->value('order_id'), 'variant_id' => $this->tee['variantId'], 'product_type' => 'physical',
            'name_snapshot' => json_encode(['en' => 'y']), 'sku_snapshot' => 'RET-2', 'quantity' => 5, 'unit_price_minor' => 100, 'discount_minor' => 0, 'tax_rate_bp' => 0, 'tax_minor' => 0,
            'line_total_minor' => 500, 'fulfilled_qty' => 0, 'returned_qty' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('return_items')->insert(['return_id' => $id, 'order_item_id' => $item2, 'quantity' => 1, 'created_at' => now()]);
        $rows = ($this->rowsOf)('returns_by_reason');
        $in = fn ($q) => $q->where('created_at', '>=', '2026-03-09 18:00:00')->where('created_at', '<', '2026-03-10 18:00:00');

        expect(array_sum(array_column($rows, 'requests')))->toBe($in(DB::table('return_requests'))->count())
            ->and(array_sum(array_column($rows, 'units')))->toEqual(DB::table('return_items as i')->join('return_requests as r', 'r.id', '=', 'i.return_id')
            ->where('r.created_at', '>=', '2026-03-09 18:00:00')->where('r.created_at', '<', '2026-03-10 18:00:00')->sum('i.quantity'))
            ->and(collect($rows)->firstWhere('reason', 'Changed their mind')['requests'])->toBe(1)->and(collect($rows)->firstWhere('reason', 'Changed their mind')['units'])->toBe(5);
    });

    it('shows a request that has no lines, with no units', function () {
        ($this->ret)('customer', 'wrong_item', 'requested', 0);
        $row = collect(($this->rowsOf)('returns_by_reason'))->firstWhere('reason', 'Wrong item');

        expect($row['requests'])->toBe(1)->and($row['units'])->toBe(0);
    });

    it('shows a reason code added later as it is, and never the customer’s own words', function () {
        $this->ret->__invoke('customer', 'brand_new_code', 'requested', 1);
        $screen = ($this->as)($this->analyst)->get('/admin/reports/returns_by_reason?from=2026-03-10&to=2026-03-10')->assertOk();

        $screen->assertSee('brand_new_code')->assertSee('Damaged')->assertDontSee('my own words');
        expect(collect(($this->rowsOf)('returns_by_reason'))->pluck('reason')->contains('brand_new_code'))->toBeTrue();
    });

    it('is open to anyone with reports, shows the share as a percentage and has no money', function () {
        $screen = ($this->as)($this->analyst)->get('/admin/reports/returns_by_reason?from=2026-03-10&to=2026-03-10')->assertOk();

        $screen->assertSee('70.00%')->assertSee('10.00%')->assertSee('Courier brought it back');
        expect(collect(app(ReportCatalogue::class)->find('returns_by_reason')->columns())->where('kind', 'money')->count())->toBe(0);
    });
});

describe('coupon performance', function () {
    beforeEach(function () {
        $save = ($this->coupon)('SAVE10');
        $eid = ($this->coupon)('EID');
        $edge = ($this->coupon)('EDGE');
        $o1 = ($this->order)(['subtotal_minor' => 10000, 'shipping_minor' => 6000, 'grand_total_minor' => 16000]);   // delivery is not part of the goods price
        $o2 = ($this->order)(['subtotal_minor' => 5000, 'grand_total_minor' => 5000]);
        $o3 = ($this->order)(['status' => 'cancelled', 'cancelled_at' => '2026-03-10 09:00:00', 'subtotal_minor' => 3000, 'grand_total_minor' => 3000]);
        $o4 = ($this->order)(['currency' => 'USD', 'subtotal_minor' => 7000, 'grand_total_minor' => 7000]);
        $o5 = ($this->order)(['subtotal_minor' => 9000, 'grand_total_minor' => 9000]);
        $o6 = ($this->order)(['subtotal_minor' => 100000, 'grand_total_minor' => 100000]);
        $o7 = ($this->order)(['subtotal_minor' => 33333, 'grand_total_minor' => 33333]);
        $this->use->__invoke($save, $o1, 10000);
        $this->use->__invoke($save, $o2, 5000);
        $this->use->__invoke($save, $o3, 3000);
        $this->use->__invoke($save, $o4, 7000);
        $this->use->__invoke($save, $o5, 9999, '2026-03-10 18:00:00');
        $this->use->__invoke($eid, $o6, 2000);
        $this->use->__invoke($eid, $o7, 1001);
        $this->use->__invoke($edge, ($this->order)(['subtotal_minor' => 7000, 'grand_total_minor' => 7000]), 700, '2026-03-09 18:00:00');
        $this->orders = compact('o1', 'o2', 'o3', 'o4', 'o5', 'o6', 'o7');
    });

    it('adds up each coupon, leaving out cancelled orders, other currencies and other days', function () {
        expect(($this->rowsOf)('coupon_performance'))->toBe([
            ['coupon' => 'SAVE10', 'uses' => 2, 'discount' => 15000, 'average_discount' => 7500, 'gross_sales' => 15000, 'discount_share' => 10000],
            ['coupon' => 'EID', 'uses' => 2, 'discount' => 3001, 'average_discount' => 1501, 'gross_sales' => 133333, 'discount_share' => 225],
            ['coupon' => 'EDGE', 'uses' => 1, 'discount' => 700, 'average_discount' => 700, 'gross_sales' => 7000, 'discount_share' => 1000],
        ]);
    });

    it('agrees with plain sums taken from the ledger tables', function () {
        $rows = ($this->rowsOf)('coupon_performance');
        $plain = DB::table('coupon_redemptions as r')->join('orders as o', 'o.id', '=', 'r.order_id')->where('r.redeemed_at', '>=', '2026-03-09 18:00:00')->where('r.redeemed_at', '<', '2026-03-10 18:00:00')
            ->where('o.status', '<>', 'cancelled')->where('o.currency', 'BDT');

        expect(array_sum(array_column($rows, 'discount')))->toEqual((clone $plain)->sum('r.amount_minor'))
            ->and(array_sum(array_column($rows, 'uses')))->toBe((clone $plain)->count())
            ->and(array_sum(array_column($rows, 'gross_sales')))->toEqual((clone $plain)->sum('o.subtotal_minor'));
    });

    it('counts an order that used two coupons in full under each', function () {
        $this->use->__invoke(DB::table('coupons')->where('code', 'EID')->value('id'), $this->orders['o1'], 500);
        $rows = array_column(($this->rowsOf)('coupon_performance'), null, 'coupon');

        expect($rows['EID'])->toMatchArray(['uses' => 3, 'discount' => 3501, 'gross_sales' => 143333])
            ->and($rows['SAVE10'])->toMatchArray(['uses' => 2, 'gross_sales' => 15000]);
    });

    it('reads a long period in pages without losing or doubling a use', function () {
        $bulk = ($this->coupon)('BULK');
        $orders = [];
        for ($i = 0; $i < 1203; $i++) {
            $orders[] = ['public_id' => strtolower((string) Str::ulid()), 'order_number' => sprintf('VM-BULK-%06d', $i), 'contact_name' => 'T', 'contact_phone' => '+8801712345678', 'locale' => 'en',
                'channel' => 'web', 'status' => 'confirmed', 'currency' => 'BDT', 'subtotal_minor' => 100, 'discount_minor' => 0, 'shipping_minor' => 0, 'cod_fee_minor' => 0, 'tax_minor' => 0,
                'grand_total_minor' => 100, 'tax_inclusive' => false, 'payment_method_code' => 'online', 'placed_at' => '2026-03-10 06:00:00', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($orders, 300) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
        $ids = DB::table('orders')->where('order_number', 'like', 'VM-BULK-%')->pluck('id')->all();
        foreach (array_chunk($ids, 300) as $chunk) {
            DB::table('coupon_redemptions')->insert(array_map(fn ($id) => ['coupon_id' => $bulk, 'order_id' => $id, 'redeemer' => 'x', 'amount_minor' => 1, 'redeemed_at' => '2026-03-10 07:00:00'], $chunk));
        }
        $row = array_column(($this->rowsOf)('coupon_performance'), null, 'coupon')['BULK'];

        expect($row)->toMatchArray(['uses' => 1203, 'discount' => 1203, 'gross_sales' => 120300, 'average_discount' => 1, 'discount_share' => 100]);
    });

    it('is for finance only, and the file has the same rows', function () {
        ($this->as)($this->analyst)->get('/admin/reports/coupon_performance?from=2026-03-10&to=2026-03-10')->assertForbidden();
        ($this->as)($this->analyst)->post('/admin/reports/coupon_performance/export', ['from' => '2026-03-10', 'to' => '2026-03-10'])->assertForbidden();
        ($this->as)($this->finance)->get('/admin/reports/coupon_performance?from=2026-03-10&to=2026-03-10')->assertOk()->assertSee('SAVE10')->assertSee('150.00')->assertSee('100.00%')->assertSee('2.25%');

        Storage::fake('private');
        config(['reporting.exports.disk' => 'private']);
        ($this->as)($this->finance)->post('/admin/reports/coupon_performance/export', ['from' => '2026-03-10', 'to' => '2026-03-10'])->assertRedirect();
        $e = DB::table('report_exports')->first();
        $lines = preg_split('/\r?\n/', trim(str_replace("\xEF\xBB\xBF", '', (string) Storage::disk('private')->get((string) $e->file_key))));

        expect($lines[1])->toBe('SAVE10,2,150.00,75.00,150.00,100.00')->and($lines[2])->toBe('EID,2,30.01,15.01,1333.33,2.25')->and($lines[3])->toBe('EDGE,1,7.00,7.00,70.00,10.00');
    });

    it('is empty when no coupon was used', function () {
        expect(($this->rowsOf)('coupon_performance', new ReportFilter('2026-03-15', '2026-03-15')))->toBe([]);
    });
});

describe('all three', function () {
    it('have the indexes their queries use', function () {
        $names = fn (string $table): array => array_column(DB::select("SHOW INDEX FROM `{$table}`"), 'Key_name');

        expect($names('shipments'))->toContain('shipments_handed_over_at_courier_index')
            ->and($names('return_requests'))->toContain('return_requests_created_at_kind_reason_index')
            ->and($names('coupon_redemptions'))->toContain('coupon_redemptions_redeemed_at_coupon_index');
    });

    it('show in Bangla with no missing words', function () {
        $this->finance->forceFill(['locale' => 'bn'])->save();
        $this->parcel->__invoke('pathao', 'delivered', '2026-03-10 05:00:00', '2026-03-10 06:30:00');
        $this->ret->__invoke('customer', 'damaged', 'requested', 1);
        $this->use->__invoke(($this->coupon)('BN10'), ($this->order)(['subtotal_minor' => 100, 'grand_total_minor' => 100]), 10);

        foreach (['delivery_performance', 'returns_by_reason', 'coupon_performance'] as $code) {
            $html = ($this->as)($this->finance->fresh())->get("/admin/reports/{$code}?from=2026-03-10&to=2026-03-10")->assertOk()->getContent();

            expect($html)->not->toContain('reporting::')->not->toContain('.columns.');
        }
        ($this->as)($this->finance->fresh())->get('/admin/reports/returns_by_reason?from=2026-03-10&to=2026-03-10')->assertSee('নষ্ট')->assertSee('ক্রেতা');
    });

    it('use a handful of queries however many couriers, reasons and coupons there are', function () {
        foreach (range(1, 25) as $i) {
            $c = 'courier'.$i;
            $this->accounts[$c] = DB::table('courier_accounts')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'courier' => $c, 'label' => $c, 'is_active' => 1, 'sort_order' => 1,
                'created_at' => now(), 'updated_at' => now()]);
            $this->parcel->__invoke($c, 'in_transit', '2026-03-10 06:00:00');
            $this->ret->__invoke('customer', 'reason'.$i, 'requested', 1);
            $this->use->__invoke(($this->coupon)('C'.$i), ($this->order)(['subtotal_minor' => 100, 'grand_total_minor' => 100]), 5);
        }
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        foreach (['delivery_performance', 'returns_by_reason', 'coupon_performance'] as $code) {
            ($this->as)($this->finance)->get("/admin/reports/{$code}?from=2026-03-10&to=2026-03-10")->assertOk();
        }

        expect($count)->toBeLessThan(60);
    });
});
