<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\SaveServiceResource;
use App\Modules\Bookings\Models\ServicePackage;
use App\Modules\Bookings\Services\SlotSearch;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Payments\Contracts\PaymentMethodCatalog;
use App\Modules\Reporting\Actions\RefreshCohorts;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Jobs\RefreshCohortsJob;
use App\Modules\Reporting\Reports\ReportCatalogue;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
 * Customer cohorts, booking utilisation, searches with no results and the daily close sheet, against scenarios worked out by hand. Rows are written straight into the ledger
 * tables. "Today" is 20 March 2026 (reportWorld). Dhaka is six hours ahead of UTC.
 *
 *   cohorts  : U1 orders in Jan, Feb and Mar; U2 in Jan (and has a cancelled order in Feb); U3's first order is 31 Jan 20:00 UTC = 1 Feb in Dhaka, then two in Feb; U4 in Feb and (28 Feb
 *              19:00 UTC =) 1 Mar; U7 in Mar. Not in any cohort: a guest, a customer with only a cancelled order, a customer whose order is in another currency.
 *   bookings : the week Mon 9 to Sun 15 March. Fitting room works Mon 9-17 and Tue 9-12 + 14-16, but Tue 10 is a holiday, Wed 11 is open 10-12 by exception, Thu 12 is closed by an
 *              exception and a Friday rule ended on 1 March: 480 + 120 = 600 minutes. The tailor works Mon 10-14 and Wed 9-10: 240 + 60 = 300 minutes.
 *   search   : the Dhaka day 10 March = 9 March 18:00 to 10 March 18:00 UTC.
 */

beforeEach(function () {
    reportWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->finance = catalogStaff(['reports.view', 'reports.export', 'reports.finance.view']);
    $this->analyst = catalogStaff(['reports.view', 'reports.export']);
    $this->as = fn (User $u) => $this->flushSession()->actingAs($u, 'admin');
    $this->rowsOf = fn (string $code, ReportFilter $f): array => iterator_to_array(app(ReportCatalogue::class)->find($code)->rows($f), false);
});

describe('customer cohorts', function () {
    beforeEach(function () {
        $u = fn (): int => catalogStaff([])->id;
        [$this->u1, $this->u2, $this->u3, $this->u4, $this->u5, $this->u6, $this->u7, $this->u8] = [$u(), $u(), $u(), $u(), $u(), $u(), $u(), $u()];
        $this->buy = fn (?int $user, string $at, int $goods, int $discount = 0, string $status = 'confirmed', string $currency = 'BDT') => ($this->order)([
            'user_id' => $user, 'placed_at' => $at, 'subtotal_minor' => $goods, 'discount_minor' => $discount, 'grand_total_minor' => $goods - $discount, 'status' => $status, 'currency' => $currency,
            'cancelled_at' => $status === 'cancelled' ? $at : null,
        ]);
        $b = $this->buy;
        $b($this->u1, '2026-01-05 06:00:00', 10000, 1000);
        $b($this->u1, '2026-02-10 06:00:00', 5000);
        $b($this->u1, '2026-03-03 06:00:00', 2000);
        $b($this->u2, '2026-01-20 06:00:00', 8000);
        $b($this->u2, '2026-02-12 06:00:00', 9000, 0, 'cancelled');
        $b($this->u3, '2026-01-31 20:00:00', 3000);
        $b($this->u3, '2026-02-20 06:00:00', 1000);
        $b($this->u3, '2026-02-20 07:00:00', 1000);
        $b($this->u4, '2026-02-15 06:00:00', 4000);
        $b($this->u4, '2026-02-28 19:00:00', 6000);
        $b($this->u5, '2026-01-08 06:00:00', 7777, 0, 'cancelled');
        $b(null, '2026-01-09 06:00:00', 5555);
        $b($this->u6, '2026-01-10 06:00:00', 4444, 0, 'confirmed', 'USD');
        $b($this->u7, '2026-03-10 06:00:00', 7000);
        $b($this->u8, '2025-12-15 06:00:00', 4000);   // December: the cohort of the year before
        $b($this->u8, '2026-01-15 06:00:00', 1000);
        $this->build = fn () => app(RefreshCohorts::class)->handle();
        $this->f = new ReportFilter('2026-01-01', '2026-03-31');
    });

    it('groups customers by the month of their first order and counts who came back', function () {
        ($this->build)();

        expect(($this->rowsOf)('customer_cohorts', $this->f))->toBe([
            ['cohort' => '2026-01', 'customers' => 2, 'month_1' => 5000, 'month_2' => 5000, 'month_3' => null, 'month_4' => null, 'month_5' => null, 'month_6' => null, 'orders' => 4, 'sales' => 24000],
            ['cohort' => '2026-02', 'customers' => 2, 'month_1' => 5000, 'month_2' => null, 'month_3' => null, 'month_4' => null, 'month_5' => null, 'month_6' => null, 'orders' => 5, 'sales' => 15000],
            ['cohort' => '2026-03', 'customers' => 1, 'month_1' => null, 'month_2' => null, 'month_3' => null, 'month_4' => null, 'month_5' => null, 'month_6' => null, 'orders' => 1, 'sales' => 7000],
        ]);
    });

    it('uses the Dhaka month, not the UTC month', function () {
        ($this->build)();
        $feb = DB::table('report_cohort_months')->where('cohort_month', '2026-02')->where('months_since', 0)->first();

        expect((int) $feb->customers)->toBe(2)->and((int) $feb->orders)->toBe(4)   // U3's first order (31 Jan 20:00 UTC) and two more, and U4's first
            ->and((int) DB::table('report_cohort_months')->where('cohort_month', '2026-02')->where('months_since', 1)->value('orders'))->toBe(1);   // U4's 1 March order (28 Feb 19:00 UTC)
    });

    it('leaves out guests, cancelled orders and other currencies, and agrees with plain sums', function () {
        ($this->build)();
        $live = DB::table('orders')->whereNotNull('user_id')->where('status', '<>', 'cancelled')->where('currency', 'BDT');

        expect((int) DB::table('report_cohort_months')->sum('orders'))->toBe((clone $live)->count())
            ->and((int) DB::table('report_cohort_months')->sum('sales_minor'))->toBe((int) (clone $live)->sum(DB::raw('subtotal_minor - discount_minor')))
            ->and(DB::table('report_cohort_months')->where('months_since', 0)->sum('customers'))->toEqual(6)   // U1, U2, U3, U4, U7, U8: not U5, U6 or the guest
            ->and(Schema::getColumnListing('report_cohort_months'))->not->toContain('user_id')->not->toContain('customer_id');
    });

    it('keeps a customer in their first month when they order again', function () {
        ($this->build)();
        ($this->buy)($this->u2, '2026-03-12 06:00:00', 3000);
        ($this->build)();

        $jan = DB::table('report_cohort_months')->where('cohort_month', '2026-01')->orderBy('months_since')->get()->keyBy('months_since');
        expect((int) $jan[0]->customers)->toBe(2)->and((int) $jan[2]->customers)->toBe(2)->and((int) $jan[2]->orders)->toBe(2)
            ->and(DB::table('report_cohort_months')->where('cohort_month', '2026-03')->value('customers'))->toEqual(1);
    });

    it('replaces the whole table, giving the same rows twice and dropping what is no longer true', function () {
        ($this->build)();
        $first = DB::table('report_cohort_months')->orderBy('cohort_month')->orderBy('months_since')->get(['cohort_month', 'months_since', 'customers', 'orders', 'sales_minor'])->toArray();
        ($this->build)();

        expect(DB::table('report_cohort_months')->orderBy('cohort_month')->orderBy('months_since')->get(['cohort_month', 'months_since', 'customers', 'orders', 'sales_minor'])->toArray())->toEqual($first);

        DB::table('orders')->where('user_id', $this->u1)->where('placed_at', '2026-02-10 06:00:00')->update(['status' => 'cancelled', 'cancelled_at' => '2026-02-11 06:00:00']);
        $result = ($this->build)();

        expect(DB::table('report_cohort_months')->where('cohort_month', '2026-01')->where('months_since', 1)->exists())->toBeFalse()
            ->and($result['customers'])->toBe(6)->and((int) DB::table('report_cohort_status')->value('cohort_rows'))->toBe($result['cohort_rows']);
    });

    it('counts months across a year end', function () {
        ($this->build)();

        expect(($this->rowsOf)('customer_cohorts', new ReportFilter('2025-12-01', '2025-12-31')))->toBe([
            ['cohort' => '2025-12', 'customers' => 1, 'month_1' => 10000, 'month_2' => 0, 'month_3' => 0, 'month_4' => null, 'month_5' => null, 'month_6' => null, 'orders' => 2, 'sales' => 5000],
        ]);
    });

    it('shows only the cohorts of the months asked for', function () {
        ($this->build)();

        expect(array_column(($this->rowsOf)('customer_cohorts', new ReportFilter('2026-02-10', '2026-02-20')), 'cohort'))->toBe(['2026-02'])
            ->and(($this->rowsOf)('customer_cohorts', new ReportFilter('2024-01-01', '2024-12-31')))->toBe([]);
    });

    it('is empty before the first nightly build and when every order is cancelled', function () {
        expect(($this->rowsOf)('customer_cohorts', $this->f))->toBe([]);
        DB::table('orders')->update(['status' => 'cancelled', 'cancelled_at' => '2026-03-15 06:00:00']);

        expect(($this->build)())->toBe(['cohort_rows' => 0, 'customers' => 0]);
    });

    it('keeps the sales column for finance and shows the rest to anyone with reports', function () {
        ($this->build)();
        $analyst = ($this->as)($this->analyst)->get('/admin/reports/customer_cohorts?from=2026-01-01&to=2026-03-31')->assertOk();
        $finance = ($this->as)($this->finance)->get('/admin/reports/customer_cohorts?from=2026-01-01&to=2026-03-31')->assertOk();

        $analyst->assertSee('2026-01')->assertSee('50.00%')->assertDontSee('Sales after discounts so far');
        $finance->assertSee('Sales after discounts so far')->assertSee('240.00');
    });

    it('is built by the nightly job and found in the schedule', function () {
        dispatch_sync(new RefreshCohortsJob);

        expect(DB::table('report_cohort_months')->count())->toBeGreaterThan(0)
            ->and(collect(app(Schedule::class)->events())->contains(fn ($e) => str_contains((string) $e->description, 'reports:refresh-cohorts')))->toBeTrue();
    });
});

describe('booking utilisation', function () {
    beforeEach(function () {
        bookingsWorld($this);
        $this->old = app(SaveServiceResource::class)->handle($this->staff, null, 'Old room', 'room', false, ['SVC-30']);
        $this->week = new ReportFilter('2026-03-09', '2026-03-15');
        $rule = fn (int $resource, int $weekday, string $from, string $to, ?string $validTo = null) => DB::table('availability_rules')->insert(['resource_id' => $resource, 'weekday' => $weekday,
            'start_time' => $from, 'end_time' => $to, 'valid_from' => null, 'valid_to' => $validTo, 'created_at' => now(), 'updated_at' => now()]);
        $rule($this->room->id, 1, '09:00:00', '17:00:00');
        $rule($this->room->id, 2, '09:00:00', '12:00:00');
        $rule($this->room->id, 2, '14:00:00', '16:00:00');
        $rule($this->room->id, 4, '10:00:00', '14:00:00');
        $rule($this->room->id, 5, '10:00:00', '12:00:00', '2026-03-01');
        $rule($this->tailor->id, 1, '10:00:00', '14:00:00');
        $rule($this->tailor->id, 3, '09:00:00', '10:00:00');
        DB::table('holidays')->insert(['on_date' => '2026-03-10', 'name' => 'Holiday', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('availability_exceptions')->insert([
            ['resource_id' => $this->room->id, 'on_date' => '2026-03-11', 'is_closed' => 0, 'start_time' => '10:00:00', 'end_time' => '12:00:00', 'note' => null, 'created_at' => now(), 'updated_at' => now()],
            ['resource_id' => $this->room->id, 'on_date' => '2026-03-12', 'is_closed' => 1, 'start_time' => null, 'end_time' => null, 'note' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $book = $this->book;
        $r = $this->room->id;
        $p = $this->package30->id;
        $book($r, $p, 'completed', '2026-03-09 04:00:00', 60);
        $book($r, $p, 'confirmed', '2026-03-09 06:00:00', 30);
        $book($r, $p, 'no_show', '2026-03-09 08:00:00', 30);
        $book($r, $p, 'held', '2026-03-09 09:00:00', 30);
        $book($r, $p, 'cancelled', '2026-03-11 04:00:00', 60);
        $book($r, $p, 'completed', '2026-03-10 05:00:00', 30);
        $book($r, $p, 'completed', '2026-03-08 18:00:00', 30);   // the very first moment of Mon 9 in Dhaka
        $book($r, $p, 'completed', '2026-03-15 18:00:00', 30);   // the very first moment of Mon 16: next week
        $book($r, $p, 'completed', '2026-03-08 17:59:59', 30);   // the last moment of Sun 8: last week
        $book($this->tailor->id, $p, 'confirmed', '2026-03-09 04:00:00', 60);
        $book($this->tailor->id, $p, 'completed', '2026-03-15 17:30:00', 30);
        $book($this->tailor->id, $p, 'in_progress', '2026-03-11 03:00:00', 30);
        app(SaveServiceResource::class)->handle($this->staff, null, 'Spare room', 'room', false, ['SVC-30']);   // switched off and never booked: not listed
        $book($this->old->id, $p, 'no_show', '2026-03-09 05:00:00', 30);
    });

    it('adds up each resource, with booked and open time worked out from the timetable', function () {
        expect(($this->rowsOf)('booking_utilisation', $this->week))->toBe([
            ['resource' => 'Fitting room', 'bookings' => 6, 'completed' => 3, 'no_shows' => 1, 'cancelled' => 1, 'no_show_rate' => 2500, 'booked_minutes' => 150, 'open_minutes' => 600, 'utilisation' => 2500],
            ['resource' => 'Old room', 'bookings' => 1, 'completed' => 0, 'no_shows' => 1, 'cancelled' => 0, 'no_show_rate' => 10000, 'booked_minutes' => 0, 'open_minutes' => 0, 'utilisation' => 0],
            ['resource' => 'Rahim (tailor)', 'bookings' => 3, 'completed' => 1, 'no_shows' => 0, 'cancelled' => 0, 'no_show_rate' => 0, 'booked_minutes' => 120, 'open_minutes' => 300, 'utilisation' => 4000],
        ]);
    });

    it('agrees with plain sums taken from the bookings table', function () {
        $rows = ($this->rowsOf)('booking_utilisation', $this->week);
        $in = DB::table('service_bookings')->where('starts_at', '>=', '2026-03-08 18:00:00')->where('starts_at', '<', '2026-03-15 18:00:00')->where('status', '<>', 'held');

        expect(array_sum(array_column($rows, 'bookings')))->toBe((clone $in)->count())
            ->and(array_sum(array_column($rows, 'no_shows')))->toBe((clone $in)->where('status', 'no_show')->count())
            ->and(array_sum(array_column($rows, 'booked_minutes')))->toBe((int) (clone $in)->whereIn('status', ['confirmed', 'in_progress', 'completed'])->sum(DB::raw('TIMESTAMPDIFF(MINUTE, starts_at, ends_at)')));
    });

    it('counts a day with an exception, a holiday and an ended rule the way slot search does', function () {
        $day = fn (string $date) => collect(($this->rowsOf)('booking_utilisation', new ReportFilter($date, $date)))->keyBy('resource')['Fitting room']['open_minutes'];

        expect($day('2026-03-09'))->toBe(480)->and($day('2026-03-10'))->toBe(0)->and($day('2026-03-11'))->toBe(120)->and($day('2026-03-12'))->toBe(0)->and($day('2026-03-13'))->toBe(0)
            ->and($day('2026-03-14'))->toBe(0);
    });

    it('shows a resource without open hours with zero use instead of failing', function () {
        $row = collect(($this->rowsOf)('booking_utilisation', new ReportFilter('2026-03-14', '2026-03-14')))->keyBy('resource');

        expect($row['Fitting room']['utilisation'])->toBe(0)->and($row['Fitting room']['bookings'])->toBe(0);
    });

    it('is open to anyone with reports and shows percentages', function () {
        ($this->as)($this->analyst)->get('/admin/reports/booking_utilisation?from=2026-03-09&to=2026-03-15')->assertOk()->assertSee('Fitting room')->assertSee('25.00%')->assertSee('100.00%')->assertSee('40.00%');
    });

    it('has the index its query uses', function () {
        expect(array_column(DB::select('SHOW INDEX FROM `service_bookings`'), 'Key_name'))->toContain('service_bookings_starts_at_resource_index');
    });

    it('keeps slot search giving the same slots', function () {
        $search = app(SlotSearch::class);
        $slots = $search->forPackage(ServicePackage::query()->findOrFail($this->package30->id), '2026-03-23', '2026-03-23', $this->tailor->public_id);

        expect($slots)->not->toBeEmpty()->and($slots[0]->startsAt->format('H:i'))->toBe('04:00');   // Monday 10:00 Dhaka
    });
});

describe('searches with no results', function () {
    beforeEach(function () {
        $q = fn (string $query, string $locale, int $count, string $at) => DB::table('search_queries')->insert(['query' => $query, 'locale' => $locale, 'result_count' => $count, 'created_at' => $at]);
        $many = fn (string $query, string $locale, int $count, int $n, string $at = '2026-03-10 05:00:00') => array_map(fn () => $q($query, $locale, $count, $at), range(1, $n));
        $many('blue shirt', 'en', 0, 2);
        $q('blue shirt', 'en', 0, '2026-03-10 07:00:00');
        $q('blue shirt', 'en', 0, '2026-03-09 18:00:00');       // the very first moment of the day
        $q('blue shirt', 'en', 0, '2026-03-10 18:00:00');       // the next day
        $q('blue shirt', 'en', 0, '2026-03-09 17:59:59');       // the day before
        $many('blue shirt', 'bn', 0, 2);
        $many('নীল শার্ট', 'bn', 0, 2);
        $many('lamp', 'en', 5, 4);
        $many('123456', 'en', 0, 2);                             // six digits: not a phone number
        $many('1234567', 'en', 0, 2);                            // seven digits: hidden
        $many('01712345678', 'en', 0, 2);
        $many('a@b.com', 'en', 0, 2);
        $many('০১৭১২৩৪৫৬৭৮', 'bn', 0, 2);
        $many('zzz', 'en', 0, 1);
        $many('qqq', 'en', 0, 1);
        $many('01999999999', 'en', 0, 1);
        $this->day = new ReportFilter('2026-03-10', '2026-03-10');
    });

    it('lists words searched at least twice, hides what looks personal and groups the rest', function () {
        expect(($this->rowsOf)('search_gaps', $this->day))->toBe([
            ['query' => 'blue shirt', 'language' => 'English', 'times' => 4, 'last_searched' => '2026-03-10'],
            ['query' => '123456', 'language' => 'English', 'times' => 2, 'last_searched' => '2026-03-10'],
            ['query' => 'blue shirt', 'language' => 'Bangla', 'times' => 2, 'last_searched' => '2026-03-10'],
            ['query' => 'নীল শার্ট', 'language' => 'Bangla', 'times' => 2, 'last_searched' => '2026-03-10'],
            ['query' => '(hidden: looks like personal data)', 'language' => null, 'times' => 8, 'last_searched' => '2026-03-10'],
            ['query' => '(searched once: not listed)', 'language' => null, 'times' => 3, 'last_searched' => null],
        ]);
    });

    it('counts every search that found nothing, once', function () {
        $rows = ($this->rowsOf)('search_gaps', $this->day);

        expect(array_sum(array_column($rows, 'times')))->toBe(DB::table('search_queries')->where('result_count', 0)->where('created_at', '>=', '2026-03-09 18:00:00')->where('created_at', '<', '2026-03-10 18:00:00')->count());
    });

    it('never puts personal-looking words on the screen or in the file', function () {
        $screen = ($this->as)($this->analyst)->get('/admin/reports/search_gaps?from=2026-03-10&to=2026-03-10')->assertOk();
        $screen->assertSee('blue shirt')->assertSee('hidden: looks like personal data')->assertDontSee('01712345678')->assertDontSee('a@b.com')->assertDontSee('1234567</')->assertDontSee('01999999999');

        Storage::fake('private');
        config(['reporting.exports.disk' => 'private']);
        ($this->as)($this->analyst)->post('/admin/reports/search_gaps/export', ['from' => '2026-03-10', 'to' => '2026-03-10'])->assertRedirect();
        $file = (string) Storage::disk('private')->get((string) DB::table('report_exports')->value('file_key'));

        expect($file)->toContain('blue shirt')->not->toContain('01712345678')->not->toContain('a@b.com')->not->toContain('01999999999')->not->toContain('০১৭১২৩৪৫৬৭৮');
    });

    it('is empty when nobody searched for nothing', function () {
        expect(($this->rowsOf)('search_gaps', new ReportFilter('2026-03-15', '2026-03-15')))->toBe([]);
    });

    it('lists at most the limit, with the rest counted in the last row', function () {
        foreach (range(1, 205) as $i) {
            DB::table('search_queries')->insert([['query' => 'word'.$i, 'locale' => 'en', 'result_count' => 0, 'created_at' => '2026-03-12 05:00:00'], ['query' => 'word'.$i, 'locale' => 'en', 'result_count' => 0, 'created_at' => '2026-03-12 05:00:01']]);
        }
        $rows = ($this->rowsOf)('search_gaps', new ReportFilter('2026-03-12', '2026-03-12'));

        expect(count($rows))->toBe(201)->and(end($rows)['times'])->toBe(10)->and(array_sum(array_column($rows, 'times')))->toBe(410);
    });

    it('shows in Bangla with no missing words', function () {
        $this->analyst->forceFill(['locale' => 'bn'])->save();
        $html = ($this->as)($this->analyst->fresh())->get('/admin/reports/search_gaps?from=2026-03-10&to=2026-03-10')->assertOk()->getContent();

        expect($html)->not->toContain('reporting::')->not->toContain('.columns.')->toContain('লুকানো');
    });
});

describe('daily close sheet', function () {
    beforeEach(function () {
        ($this->scenario)();
        ($this->work)();
        $this->cod = app(PaymentMethodCatalog::class)->label('cod', 'en');
        $this->online = app(PaymentMethodCatalog::class)->label('online', 'en');
        $this->tenth = new ReportFilter('2026-03-10', '2026-03-10');
    });

    it('gives the figures worked out by hand for the first day', function () {
        expect(($this->rowsOf)('daily_close', $this->tenth))->toBe([
            ['section' => 'Sales', 'line' => 'Orders (amount: billed total)', 'count' => 3, 'amount' => 174000],
            ['section' => 'Sales', 'line' => 'Gross sales', 'count' => null, 'amount' => 170000],
            ['section' => 'Sales', 'line' => 'Discounts', 'count' => null, 'amount' => 10000],
            ['section' => 'Sales', 'line' => 'Delivery charges', 'count' => null, 'amount' => 12000],
            ['section' => 'Sales', 'line' => 'Cash-on-delivery fees', 'count' => null, 'amount' => 2000],
            ['section' => 'Sales', 'line' => 'Tax billed', 'count' => null, 'amount' => 2608],
            ['section' => 'Sales', 'line' => 'Cancelled orders', 'count' => 1, 'amount' => 30000],
            ['section' => 'Money received', 'line' => $this->online, 'count' => null, 'amount' => 126000],
            ['section' => 'Money received', 'line' => 'Total received', 'count' => null, 'amount' => 126000],
            ['section' => 'Money received', 'line' => 'Money given back', 'count' => null, 'amount' => 0],
            ['section' => 'Result', 'line' => 'Net sales', 'count' => null, 'amount' => 160000],
        ]);
    });

    it('shows the cash on delivery recorded on the day it was collected, and the money given back', function () {
        $rows = collect(($this->rowsOf)('daily_close', new ReportFilter('2026-03-11', '2026-03-11')))->keyBy('line');

        expect($rows[$this->cod]['amount'])->toBe(58000)->and($rows['Total received']['amount'])->toBe(58000)->and($rows['Money given back']['amount'])->toBe(50000)
            ->and($rows['Net sales']['amount'])->toBe(-20000)->and($rows['Orders (amount: billed total)']['count'])->toBe(0);
    });

    it('equals the sales summary for the same period', function () {
        $period = new ReportFilter('2026-03-10', '2026-03-11');
        $close = collect(($this->rowsOf)('daily_close', $period))->keyBy('line');
        $days = collect(($this->rowsOf)('sales_by_day', $period));

        expect($close['Gross sales']['amount'])->toBe($days->sum('gross_sales'))->and($close['Discounts']['amount'])->toBe($days->sum('discounts'))
            ->and($close['Total received']['amount'])->toBe($days->sum('collected'))->and($close['Money given back']['amount'])->toBe($days->sum('refunded'))
            ->and($close['Net sales']['amount'])->toBe($days->sum('net_sales'))->and($close['Orders (amount: billed total)']['amount'])->toBe($days->sum('billed_total'));
    });

    it('is for finance only, and the file has the same rows', function () {
        ($this->as)($this->analyst)->get('/admin/reports/daily_close?from=2026-03-10&to=2026-03-10')->assertForbidden();
        ($this->as)($this->analyst)->post('/admin/reports/daily_close/export', ['from' => '2026-03-10', 'to' => '2026-03-10'])->assertForbidden();
        ($this->as)($this->finance)->get('/admin/reports/daily_close?from=2026-03-10&to=2026-03-10')->assertOk()->assertSee('Gross sales')->assertSee('1,700.00')->assertSee('Net sales');

        Storage::fake('private');
        config(['reporting.exports.disk' => 'private']);
        ($this->as)($this->finance)->post('/admin/reports/daily_close/export', ['from' => '2026-03-10', 'to' => '2026-03-10'])->assertRedirect();
        $lines = preg_split('/\r?\n/', trim(str_replace("\xEF\xBB\xBF", '', (string) Storage::disk('private')->get((string) DB::table('report_exports')->value('file_key')))));

        expect($lines[0])->toBe('Part,Line,Count,Amount')->and($lines[1])->toBe('Sales,"Orders (amount: billed total)",3,1740.00')->and($lines[2])->toBe('Sales,"Gross sales",,1700.00')->and(end($lines))->toBe('Result,"Net sales",,1600.00');
    });

    it('shows in Bangla with no missing words', function () {
        $this->finance->forceFill(['locale' => 'bn'])->save();
        $html = ($this->as)($this->finance->fresh())->get('/admin/reports/daily_close?from=2026-03-10&to=2026-03-10')->assertOk()->getContent();

        expect($html)->not->toContain('reporting::')->not->toContain('.lines.')->not->toContain('.sections.')->toContain('নিট বিক্রি');
    });
});

describe('all four', function () {
    it('use a handful of queries however many rows there are', function () {
        bookingsWorld($this);
        foreach (range(1, 20) as $i) {
            $r = app(SaveServiceResource::class)->handle($this->staff, null, 'Room '.$i, 'room', true, ['SVC-30']);
            DB::table('availability_rules')->insert(['resource_id' => $r->id, 'weekday' => 1, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('search_queries')->insert([['query' => 'q'.$i, 'locale' => 'en', 'result_count' => 0, 'created_at' => '2026-03-10 05:00:00'], ['query' => 'q'.$i, 'locale' => 'en', 'result_count' => 0, 'created_at' => '2026-03-10 05:00:01']]);
        }
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        foreach (['booking_utilisation', 'search_gaps', 'customer_cohorts', 'daily_close'] as $code) {
            ($this->as)($this->finance)->get("/admin/reports/{$code}?from=2026-03-09&to=2026-03-15")->assertOk();
        }

        expect($count)->toBeLessThan(80);
    });

    it('show cohorts and bookings in Bangla with no missing words', function () {
        $this->finance->forceFill(['locale' => 'bn'])->save();
        foreach (['customer_cohorts', 'booking_utilisation'] as $code) {
            $html = ($this->as)($this->finance->fresh())->get("/admin/reports/{$code}?from=2026-03-09&to=2026-03-15")->assertOk()->getContent();

            expect($html)->not->toContain('reporting::')->not->toContain('.columns.');
        }
    });
});
