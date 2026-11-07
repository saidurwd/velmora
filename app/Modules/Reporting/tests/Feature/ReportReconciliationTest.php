<?php

declare(strict_types=1);

use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Jobs\RefreshRecentReportsJob;
use App\Modules\Reporting\Jobs\RefreshWindowReportsJob;
use App\Modules\Reporting\Metrics\MetricCatalogue;
use App\Modules\Reporting\Services\ReportReader;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The reports must add up to the ledgers. Orders, payments, refunds and cash collections are written straight into their tables (the ledgers), the summary is worked
 * out from them, and every figure is compared with a number worked out by hand AND with a plain sum taken from the ledger tables.
 *
 * The scenario, on 10 March 2026 (Dhaka), figures in paisa:
 *   O1 website, online, English: goods 100,000, discount 10,000, delivery 6,000            -> billed  96,000
 *   O2 staff, cash on delivery, Bangla: goods 50,000, delivery 6,000, cash fee 2,000        -> billed  58,000
 *   O3 website, online, English, CANCELLED: goods 30,000                                    -> billed  30,000 (not a sale)
 *   O4 website, online, English, tax included: goods 20,000, of which tax 2,608             -> billed  20,000
 *   P1 O1 paid 96,000 online on the 10th, later 20,000 of it refunded on the 11th (R1), another refund R3 of 5,000 only asked for
 *   P2 O2 cash 58,000 collected at the door on the 11th
 *   P3 O3 paid 30,000 online on the 10th, all of it refunded on the 11th (R2) because the order was cancelled
 */

beforeEach(function () {
    reportWorld($this);
});

describe('the scenario adds up', function () {
    it('gives the figures worked out by hand for the first day', function () {
        ($this->scenario)();
        ($this->work)();

        expect(($this->numbers)('2026-03-10', '2026-03-10'))->toBe([
            'orders' => 3, 'units_sold' => 0, 'returned_units' => 0, 'return_rate' => 0, 'gross_sales' => 170000, 'discounts' => 10000, 'sales_after_discounts' => 160000, 'delivery_charges' => 12000, 'cod_fees' => 2000, 'tax' => 2608, 'billed_total' => 174000,
            'average_order_value' => 58000, 'cancelled_orders' => 1, 'cancelled_value' => 30000, 'collected' => 126000, 'refunded' => 0, 'net_sales' => 160000,
        ]);
    });

    it('gives the figures for the second day, where only money moved', function () {
        ($this->scenario)();
        ($this->work)();

        expect(($this->numbers)('2026-03-11', '2026-03-11'))->toBe([
            'orders' => 0, 'units_sold' => 0, 'returned_units' => 0, 'return_rate' => 0, 'gross_sales' => 0, 'discounts' => 0, 'sales_after_discounts' => 0, 'delivery_charges' => 0, 'cod_fees' => 0, 'tax' => 0, 'billed_total' => 0,
            'average_order_value' => 0, 'cancelled_orders' => 0, 'cancelled_value' => 0, 'collected' => 58000, 'refunded' => 50000, 'net_sales' => -20000,
        ]);
    });

    it('takes the refund of a cancelled order out of money given back but not out of net sales', function () {
        ($this->scenario)();
        ($this->work)();
        $both = ($this->numbers)();

        // net sales = goods 170,000 - discounts 10,000 - the refund of the order that was NOT cancelled (R1, 20,000); R2 (30,000) belongs to the cancelled O3 and was never a sale
        expect($both['net_sales'])->toBe(140000)->and($both['refunded'])->toBe(50000)->and($both['collected'])->toBe(184000)
            ->and($both['average_order_value'])->toBe(58000)->and($both['cancelled_value'])->toBe(30000);
    });

    it('equals plain sums taken straight from the ledger tables', function () {
        ($this->scenario)();
        ($this->work)();
        $report = ($this->numbers)();

        $billed = (int) DB::table('orders')->where('status', '<>', 'cancelled')->sum('grand_total_minor');
        $gross = (int) DB::table('orders')->where('status', '<>', 'cancelled')->sum('subtotal_minor');
        $discount = (int) DB::table('orders')->where('status', '<>', 'cancelled')->sum('discount_minor');
        $collected = (int) DB::table('payments')->where('status', 'paid')->sum('captured_minor');
        $refunded = (int) DB::table('refunds')->where('status', 'succeeded')->sum('amount_minor');
        $cash = (int) DB::table('cod_collections')->sum('amount_minor');
        $cashReport = ($this->numbers)('2026-03-10', '2026-03-11', null, 'cod')['collected'];

        expect($report['billed_total'])->toBe($billed)->and($report['gross_sales'])->toBe($gross)->and($report['discounts'])->toBe($discount)
            ->and($report['collected'])->toBe($collected)->and($report['refunded'])->toBe($refunded)->and($cashReport)->toBe($cash);
        // and the order lines of the ledger: gross - discounts + delivery + cash fee + tax (when not included) = billed
        $rows = DB::table('orders')->where('status', '<>', 'cancelled')->get();
        expect($report['gross_sales'] - $report['discounts'] + $report['delivery_charges'] + $report['cod_fees'] + $rows->where('tax_inclusive', 0)->sum('tax_minor'))->toBe($report['billed_total']);
    });

    it('can be narrowed by how the order was placed, the payment method and the language', function () {
        ($this->scenario)();
        ($this->work)();

        expect(($this->numbers)(channel: 'admin')['billed_total'])->toBe(58000)
            ->and(($this->numbers)(channel: 'admin')['collected'])->toBe(58000)
            ->and(($this->numbers)(method: 'cod')['orders'])->toBe(1)
            ->and(($this->numbers)(locale: 'bn')['gross_sales'])->toBe(50000)
            ->and(($this->numbers)(channel: 'web', locale: 'en')['billed_total'])->toBe(116000)
            ->and(($this->numbers)(channel: 'web', locale: 'en')['refunded'])->toBe(50000)   // R1 and R2 both belong to website orders
            ->and(($this->numbers)(channel: 'api')['orders'])->toBe(0);
    });

    it('counts the payments and refunds behind the money', function () {
        ($this->scenario)();
        ($this->work)();

        expect((int) DB::table('report_daily_payments')->where('day', '2026-03-10')->sum('collected_count'))->toBe(2)      // P1 and P3
            ->and((int) DB::table('report_daily_payments')->where('day', '2026-03-11')->sum('collected_count'))->toBe(1)   // P2 (cash)
            ->and((int) DB::table('report_daily_payments')->where('day', '2026-03-11')->sum('refund_count'))->toBe(2)      // R1 and R2; R3 was only asked for
            ->and((int) DB::table('report_daily_payments')->where('day', '2026-03-10')->sum('refund_count'))->toBe(0);
    });

    it('leaves out money in another currency', function () {
        ($this->scenario)();
        ($this->order)(['currency' => 'USD', 'subtotal_minor' => 99900, 'grand_total_minor' => 99900]);
        ($this->work)();

        expect(($this->numbers)()['gross_sales'])->toBe(170000)->and(DB::table('report_daily_sales')->where('currency', 'USD')->sum('gross_minor'))->toEqual(99900);
    });
});

describe('days', function () {
    it('puts an order on the Dhaka day it was placed, whatever the UTC date', function () {
        ($this->order)(['placed_at' => '2026-03-10 17:59:59.999999', 'subtotal_minor' => 1000, 'grand_total_minor' => 1000]);   // 23:59:59 on the 10th in Dhaka
        ($this->order)(['placed_at' => '2026-03-10 18:00:00', 'subtotal_minor' => 2000, 'grand_total_minor' => 2000]);          // midnight: the 11th
        ($this->order)(['placed_at' => '2026-03-09 18:00:00', 'subtotal_minor' => 400, 'grand_total_minor' => 400]);            // midnight: the 10th
        ($this->order)(['placed_at' => '2026-03-09 17:59:59', 'subtotal_minor' => 80, 'grand_total_minor' => 80]);              // still the 9th
        ($this->work)('2026-03-09', '2026-03-11');

        expect(($this->numbers)('2026-03-09', '2026-03-09')['gross_sales'])->toBe(80)
            ->and(($this->numbers)('2026-03-10', '2026-03-10')['gross_sales'])->toBe(1400)
            ->and(($this->numbers)('2026-03-11', '2026-03-11')['gross_sales'])->toBe(2000);
    });

    it('puts money on the day it was paid or given back, in Dhaka time', function () {
        $o = ($this->order)(['subtotal_minor' => 5000, 'grand_total_minor' => 5000]);
        $p = ($this->pay)($o, 5000, '2026-03-10 18:30:00');   // 00:30 on the 11th in Dhaka
        ($this->refund)($p, $o, 1000, 'succeeded', '2026-03-10 17:30:00');   // 23:30 on the 10th
        ($this->pay)($o, 70, '2026-03-10 18:00:00');                          // exactly midnight: the 11th
        ($this->pay)($o, 9, '2026-03-10 17:59:59.999999');                     // the last moment of the 10th
        ($this->refund)($p, $o, 3, 'succeeded', '2026-03-10 18:00:00');       // exactly midnight: the 11th
        ($this->work)();

        expect(($this->numbers)('2026-03-10', '2026-03-10')['collected'])->toBe(9)->and(($this->numbers)('2026-03-10', '2026-03-10')['refunded'])->toBe(1000)
            ->and(($this->numbers)('2026-03-11', '2026-03-11')['collected'])->toBe(5070)->and(($this->numbers)('2026-03-11', '2026-03-11')['refunded'])->toBe(3);
    });

    it('refuses a day that has not started', function () {
        expect(fn () => ($this->refresh)('2026-03-21'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => ($this->refresh)('2026-02-30'))->toThrow(InvalidArgumentException::class);
        ($this->refresh)('2026-03-20');   // today is fine

        expect(DB::table('report_day_status')->count())->toBe(1);
    });
});

describe('refreshing', function () {
    $snapshot = fn () => json_encode([
        DB::table('report_daily_sales')->orderBy('day')->orderBy('channel')->orderBy('payment_method')->orderBy('locale')->get()->map(fn ($r) => collect((array) $r)->except(['id', 'refreshed_at'])->all())->all(),
        DB::table('report_daily_payments')->orderBy('day')->orderBy('channel')->orderBy('payment_method')->orderBy('locale')->get()->map(fn ($r) => collect((array) $r)->except(['id', 'refreshed_at'])->all())->all(),
    ]);

    it('gives the same rows when it is run again', function () use ($snapshot) {
        ($this->scenario)();
        ($this->work)();
        $first = $snapshot();
        $this->travel(5)->minutes();
        ($this->work)();

        expect($snapshot())->toBe($first)->and(DB::table('report_day_status')->count())->toBe(2);
    });

    it('replaces a day as a whole when the ledgers changed', function () {
        $ids = ($this->scenario)();
        ($this->work)();
        DB::table('orders')->where('id', $ids['o2'])->update(['status' => 'cancelled', 'cancelled_at' => '2026-03-12 00:00:00']);
        ($this->refresh)('2026-03-10');

        expect(($this->numbers)('2026-03-10', '2026-03-10')['orders'])->toBe(2)->and(($this->numbers)('2026-03-10', '2026-03-10')['cancelled_orders'])->toBe(2)
            ->and(($this->numbers)('2026-03-10', '2026-03-10')['billed_total'])->toBe(116000);
    });

    it('removes what no longer exists', function () {
        ($this->scenario)();
        ($this->work)();
        // a row for a group that has nothing behind it any more (for example left by an older version of the rules)
        DB::table('report_daily_sales')->insert(['day' => '2026-03-11', 'channel' => 'api', 'payment_method' => 'online', 'locale' => 'en', 'currency' => 'BDT', 'orders' => 4, 'gross_minor' => 9, 'discounts_minor' => 0,
            'shipping_minor' => 0, 'cod_fee_minor' => 0, 'tax_minor' => 0, 'billed_minor' => 9, 'cancelled_orders' => 0, 'cancelled_billed_minor' => 0, 'refreshed_at' => now()]);
        DB::table('report_daily_payments')->insert(['day' => '2026-03-11', 'channel' => 'api', 'payment_method' => 'online', 'locale' => 'en', 'currency' => 'BDT', 'collected_minor' => 77, 'collected_count' => 1,
            'refunded_minor' => 0, 'refunded_live_minor' => 0, 'refund_count' => 0, 'refreshed_at' => now()]);
        expect(($this->numbers)('2026-03-11', '2026-03-11')['orders'])->toBe(4)->and(($this->numbers)('2026-03-11', '2026-03-11', channel: 'api')['collected'])->toBe(77);
        ($this->refresh)('2026-03-11');

        expect(($this->numbers)('2026-03-11', '2026-03-11')['orders'])->toBe(0)->and(DB::table('report_daily_sales')->where('day', '2026-03-11')->count())->toBe(0)
            ->and(($this->numbers)('2026-03-11', '2026-03-11', channel: 'api')['collected'])->toBe(0);
    });

    it('never has two rows for the same day and group', function () {
        ($this->scenario)();
        ($this->work)();
        $row = (array) DB::table('report_daily_sales')->first();
        unset($row['id']);

        expect(fn () => DB::table('report_daily_sales')->insert($row))->toThrow(UniqueConstraintViolationException::class);
    });

    it('records when and how each day was worked out', function () {
        ($this->scenario)();
        ($this->refresh)('2026-03-10', 'nightly');
        $this->travel(1)->hours();
        ($this->refresh)('2026-03-10', 'rebuild');

        $status = DB::table('report_day_status')->first();
        expect($status->trigger)->toBe('rebuild')->and((int) $status->sales_rows)->toBe(2)->and((int) $status->payment_rows)->toBe(1)->and(DB::table('report_day_status')->count())->toBe(1);
    });

    it('works today and yesterday out every 15 minutes, and the last 35 days every night', function () {
        $old = ($this->order)(['placed_at' => '2026-01-05 06:00:00', 'subtotal_minor' => 700, 'grand_total_minor' => 700]);
        $recent = ($this->order)(['placed_at' => '2026-03-19 06:00:00', 'subtotal_minor' => 300, 'grand_total_minor' => 300]);
        app()->call([app(RefreshRecentReportsJob::class), 'handle']);

        expect(DB::table('report_day_status')->pluck('day')->map(fn ($d) => (string) $d)->sort()->values()->all())->toBe(['2026-03-19', '2026-03-20']);

        app()->call([app(RefreshWindowReportsJob::class), 'handle']);
        $days = DB::table('report_day_status')->orderBy('day')->pluck('day')->map(fn ($d) => (string) $d)->all();
        expect($days)->toHaveCount(35)->and($days[0])->toBe('2026-02-14')->and(end($days))->toBe('2026-03-20')
            ->and(DB::table('report_daily_sales')->where('day', '2026-01-05')->exists())->toBeFalse();   // 5 January is outside the window: not touched
    });

    it('changes an old day only through the rebuild command, and writes it to the audit log', function () {
        $old = ($this->order)(['placed_at' => '2026-01-05 06:00:00', 'subtotal_minor' => 700, 'grand_total_minor' => 700]);
        expect(Artisan::call('reports:rebuild', ['from' => '2026-01-05', 'to' => '2026-01-05']))->toBe(0);
        DB::table('orders')->where('id', $old)->update(['status' => 'cancelled', 'cancelled_at' => '2026-03-01 00:00:00']);
        app()->call([app(RefreshWindowReportsJob::class), 'handle']);

        expect(($this->numbers)('2026-01-05', '2026-01-05')['gross_sales'])->toBe(700);   // a closed day does not move on its own

        Artisan::call('reports:rebuild', ['from' => '2026-01-05', 'to' => '2026-01-05']);
        $audit = DB::table('audit_events')->where('action', 'reports.rebuilt')->orderByDesc('id')->first();

        expect(($this->numbers)('2026-01-05', '2026-01-05')['gross_sales'])->toBe(0)->and(DB::table('audit_events')->where('action', 'reports.rebuilt')->count())->toBe(2)
            ->and(json_decode((string) $audit->after, true))->toBe(['from' => '2026-01-05', 'to' => '2026-01-05', 'days' => 1]);
    });

    it('refuses a rebuild that makes no sense', function () {
        expect(Artisan::call('reports:rebuild', ['from' => '2026-03-11', 'to' => '2026-03-10']))->not->toBe(0)
            ->and(Artisan::call('reports:rebuild', ['from' => 'soon', 'to' => '2026-03-10']))->not->toBe(0)
            ->and(Artisan::call('reports:rebuild', ['from' => '2026-03-19', 'to' => '2026-03-25']))->not->toBe(0)
            ->and(Artisan::call('reports:rebuild', ['from' => '2023-01-01', 'to' => '2026-03-20']))->not->toBe(0);   // more than 800 days
        expect(DB::table('report_day_status')->count())->toBe(0);
    });
});

describe('indexes', function () {
    it('has the indexes the daily queries read through', function () {
        expect(Schema::hasIndex('orders', ['placed_at']))->toBeTrue()
            ->and(Schema::hasIndex('payments', ['paid_at']))->toBeTrue()
            ->and(Schema::hasIndex('refunds', ['status', 'updated_at']))->toBeTrue()
            ->and(Schema::hasIndex('report_daily_sales', ['day', 'channel', 'payment_method', 'locale', 'currency'], 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('report_daily_payments', ['day', 'channel', 'payment_method', 'locale', 'currency'], 'unique'))->toBeTrue();
    });
});

describe('definitions', function () {
    it('has each metric once, with words in both languages and a unique name', function () {
        $codes = array_map(fn ($m) => $m->code(), app(MetricCatalogue::class)->all());

        expect($codes)->toBe(array_values(array_unique($codes)));
        foreach ($codes as $code) {
            foreach (['en', 'bn'] as $locale) {
                expect(trans("reporting::metrics.{$code}.label", [], $locale))->not->toBe("reporting::metrics.{$code}.label")
                    ->and(trans("reporting::metrics.{$code}.definition", [], $locale))->not->toBe("reporting::metrics.{$code}.definition");
            }
        }
    });

    it('rounds the average order value to the nearest paisa', function () {
        ($this->order)(['subtotal_minor' => 100, 'grand_total_minor' => 100]);
        ($this->order)(['subtotal_minor' => 101, 'grand_total_minor' => 101]);
        ($this->order)(['subtotal_minor' => 101, 'grand_total_minor' => 101]);
        ($this->work)('2026-03-10', '2026-03-10');

        expect(($this->numbers)('2026-03-10', '2026-03-10')['average_order_value'])->toBe(101)   // 302 / 3 = 100.67 -> 101
            ->and(($this->numbers)('2026-03-12', '2026-03-12')['average_order_value'])->toBe(0);   // no orders: no division
    });

    it('keeps the finance figures out of the numbers unless asked', function () {
        ($this->scenario)();
        ($this->work)();
        $inputs = app(ReportReader::class)->inputs(new ReportFilter('2026-03-10', '2026-03-11'));

        expect(array_keys(app(MetricCatalogue::class)->compute($inputs, false)))->not->toContain('collected', 'refunded', 'net_sales')
            ->and(array_keys(app(MetricCatalogue::class)->compute($inputs, true)))->toContain('collected', 'refunded', 'net_sales');
    });
});

describe('coverage', function () {
    it('says which days have not been worked out', function () {
        ($this->scenario)();
        ($this->refresh)('2026-03-10');
        $cover = app(ReportReader::class)->coverage(new ReportFilter('2026-03-09', '2026-03-12'));

        expect($cover['days'])->toBe(4)->and($cover['worked_out'])->toBe(1)->and($cover['missing'])->toBe(['2026-03-09', '2026-03-11', '2026-03-12'])->and($cover['as_of'])->not->toBeNull();
    });

    it('does not count days that have not started as missing', function () {
        ($this->refresh)('2026-03-20');
        $cover = app(ReportReader::class)->coverage(new ReportFilter('2026-03-20', '2026-03-25'));

        expect($cover['days'])->toBe(1)->and($cover['missing'])->toBe([]);
    });
});
