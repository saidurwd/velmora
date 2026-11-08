<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Reports\ReportCatalogue;
use App\Modules\Reporting\Services\ReportValues;
use Illuminate\Support\Facades\DB;

/* The report catalogue on screen: who sees which report, what each one shows, and that every report's figures agree with the ledgers and with the sales summary. */

beforeEach(function () {
    shopWorld($this);   // languages, tax, prices, stock places, payment methods, a few products
    reportWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->finance = catalogStaff(['reports.view', 'reports.export', 'reports.finance.view', 'inventory.view']);
    $this->analyst = catalogStaff(['reports.view', 'reports.export']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->as = fn (User $u) => $this->flushSession()->actingAs($u, 'admin');
    ($this->scenario)();
    ($this->work)();
    $this->q = '?from=2026-03-10&to=2026-03-11';
});

describe('the list', function () {
    it('shows each person only the reports they may open', function () {
        $codes = fn (User $u) => array_map(fn ($r) => $r->code(), app(ReportCatalogue::class)->visibleFor($u));

        expect($codes($this->finance))->toBe(['sales_by_day', 'payment_methods', 'channels_languages', 'refunds_cancellations', 'best_sellers', 'sales_by_product', 'sales_by_category', 'sales_by_brand', 'tax_summary', 'delivery_performance', 'returns_by_reason', 'coupon_performance', 'customer_cohorts', 'booking_utilisation', 'search_gaps', 'daily_close', 'cod_aging', 'low_stock', 'stock_aging'])
            ->and($codes($this->analyst))->toBe(['sales_by_day', 'payment_methods', 'channels_languages', 'refunds_cancellations', 'best_sellers', 'sales_by_product', 'sales_by_category', 'sales_by_brand', 'delivery_performance', 'returns_by_reason', 'customer_cohorts', 'booking_utilisation', 'search_gaps'])   // not tax, coupons, the close sheet or cash aging (finance), not stock (inventory)
            ->and($codes($this->nobody))->toBe([]);
    });

    it('is a page of links to those reports', function () {
        ($this->as)($this->analyst)->get('/admin/reports')->assertOk()->assertSee('Sales by day')->assertSee('Payment methods')->assertDontSee('Cash on delivery: aging')->assertDontSee('Low stock');
        ($this->as)($this->finance)->get('/admin/reports')->assertOk()->assertSee('Cash on delivery: aging')->assertSee('Low stock')->assertSee('Stock aging');
        ($this->as)($this->nobody)->get('/admin/reports')->assertForbidden();
        auth('admin')->logout();
        $this->flushSession()->get('/admin/reports')->assertRedirect('/admin/login');
    });
});

describe('who may open which report', function () {
    $matrix = [
        // report                  analyst, finance, nobody
        'sales_by_day' => [200, 200, 403],
        'payment_methods' => [200, 200, 403],
        'channels_languages' => [200, 200, 403],
        'refunds_cancellations' => [200, 200, 403],
        'best_sellers' => [200, 200, 403],
        'sales_by_product' => [200, 200, 403],
        'sales_by_category' => [200, 200, 403],
        'sales_by_brand' => [200, 200, 403],
        'tax_summary' => [403, 200, 403],
        'delivery_performance' => [200, 200, 403],
        'returns_by_reason' => [200, 200, 403],
        'coupon_performance' => [403, 200, 403],
        'customer_cohorts' => [200, 200, 403],
        'booking_utilisation' => [200, 200, 403],
        'search_gaps' => [200, 200, 403],
        'daily_close' => [403, 200, 403],
        'cod_aging' => [403, 200, 403],
        'low_stock' => [403, 200, 403],
        'stock_aging' => [403, 200, 403],
    ];

    it('answers every report according to the permissions it needs', function () use ($matrix) {
        foreach ($matrix as $code => [$analyst, $finance, $nobody]) {
            expect(($this->as)($this->analyst)->get("/admin/reports/{$code}")->status())->toBe($analyst, "{$code} for the analyst")
                ->and(($this->as)($this->finance)->get("/admin/reports/{$code}")->status())->toBe($finance, "{$code} for finance")
                ->and(($this->as)($this->nobody)->get("/admin/reports/{$code}")->status())->toBe($nobody, "{$code} for staff without reports");
            auth('admin')->logout();
            $this->flushSession()->get("/admin/reports/{$code}")->assertRedirect('/admin/login');
        }
    });

    it('covers every report in the catalogue (a new report must be added to the matrix above)', function () use ($matrix) {
        $codes = array_map(fn ($r) => $r->code(), app(ReportCatalogue::class)->all());

        expect($codes)->toBe(array_keys($matrix));
    });

    it('does not find a report that does not exist', function () {
        ($this->as)($this->finance)->get('/admin/reports/nothing_here')->assertNotFound();
    });

    it('hides the finance columns from someone without finance permission', function () {
        $analyst = ($this->as)($this->analyst)->get('/admin/reports/payment_methods'.$this->q)->assertOk();
        $finance = ($this->as)($this->finance)->get('/admin/reports/payment_methods'.$this->q)->assertOk();

        $analyst->assertSee('Billed total')->assertDontSee('Money received')->assertDontSee('Money given back');
        $finance->assertSee('Money received')->assertSee('Money given back');
    });
});

describe('what each report shows', function () {
    it('sales by day: one row a day, agreeing with the ledgers', function () {
        $report = app(ReportCatalogue::class)->find('sales_by_day');
        $rows = iterator_to_array($report->rows(new ReportFilter('2026-03-10', '2026-03-12')), false);

        expect($rows)->toHaveCount(3)
            ->and($rows[0]['day'])->toBe('2026-03-10')->and($rows[0]['gross_sales'])->toBe(170000)->and($rows[0]['billed_total'])->toBe(174000)->and($rows[0]['collected'])->toBe(126000)
            ->and($rows[1]['day'])->toBe('2026-03-11')->and($rows[1]['refunded'])->toBe(50000)->and($rows[1]['net_sales'])->toBe(-20000)
            ->and($rows[2]['orders'])->toBe(0)   // a day without figures is still a row
            ->and(array_sum(array_column($rows, 'billed_total')))->toBe((int) DB::table('orders')->where('status', '<>', 'cancelled')->sum('grand_total_minor'));
    });

    it('payment methods: the shares add up to the whole', function () {
        $rows = iterator_to_array(app(ReportCatalogue::class)->find('payment_methods')->rows(new ReportFilter('2026-03-10', '2026-03-11')), false);
        $byMethod = array_column($rows, null, 'method');

        expect($rows)->toHaveCount(2)->and(array_sum(array_column($rows, 'share')))->toBe(10000)
            ->and(array_sum(array_column($rows, 'billed_total')))->toBe(174000)
            ->and(array_sum(array_column($rows, 'collected')))->toBe(184000)->and(array_sum(array_column($rows, 'refunded')))->toBe(50000);
        $cod = collect($rows)->firstWhere('orders', 1);
        expect($cod['billed_total'])->toBe(58000)->and($cod['share'])->toBe(3333)->and($cod['collected'])->toBe(58000);   // 58,000 of 174,000 = 33.33%
        expect($byMethod)->toHaveCount(2);
    });

    it('channels and languages: both splits cover the same orders', function () {
        $rows = iterator_to_array(app(ReportCatalogue::class)->find('channels_languages')->rows(new ReportFilter('2026-03-10', '2026-03-11')), false);
        $channel = array_filter($rows, fn ($r) => $r['split'] === 'channel');
        $language = array_filter($rows, fn ($r) => $r['split'] === 'language');

        expect(array_sum(array_column($channel, 'billed_total')))->toBe(174000)->and(array_sum(array_column($language, 'billed_total')))->toBe(174000)
            ->and(array_column($channel, 'group'))->toBe(['admin', 'web'])->and(array_column($language, 'group'))->toBe(['bn', 'en'])
            ->and(array_sum(array_column($channel, 'orders')))->toBe(3);
    });

    it('refunds and cancellations: per day', function () {
        $rows = iterator_to_array(app(ReportCatalogue::class)->find('refunds_cancellations')->rows(new ReportFilter('2026-03-10', '2026-03-11')), false);

        expect($rows[0])->toBe(['day' => '2026-03-10', 'cancelled_orders' => 1, 'cancelled_value' => 30000, 'refunded' => 0])
            ->and($rows[1])->toBe(['day' => '2026-03-11', 'cancelled_orders' => 0, 'cancelled_value' => 0, 'refunded' => 50000]);
    });

    it('cash on delivery aging: uncollected and unremitted cash in age buckets', function () {
        // O2 (cash 58,000, collected on the 11th) has no courier statement; today is 20 March: 9 days old
        $rows = iterator_to_array(app(ReportCatalogue::class)->find('cod_aging')->rows(new ReportFilter('2026-03-20', '2026-03-20')), false);
        $unremitted = collect($rows)->where('kind', 'unremitted');

        expect($rows)->toHaveCount(8)->and($unremitted->sum('amount'))->toBe(58000)->and($unremitted->firstWhere('age_days', '8-14')['orders'])->toBe(1)
            ->and($unremitted->firstWhere('age_days', '0-3')['orders'])->toBe(0);
    });

    it('low stock and stock aging name the product and the place', function () {
        ($this->setStock)($this->tee, 0);
        DB::table('stock_levels')->where('variant_id', $this->lamp['variantId'])->update(['low_stock_threshold' => 30]);   // the lamp has 20: below its level
        $low = iterator_to_array(app(ReportCatalogue::class)->find('low_stock')->rows(new ReportFilter('2026-03-20', '2026-03-20')), false);
        $names = array_column($low, 'product');

        expect($names)->toContain('Cotton Tee')->toContain('Brass Lamp')->and($low[0]['product'])->toBe('Cotton Tee')->and($low[0]['available'])->toBe(0)->and($low[0]['sku'])->toBe('TEE-1')
            ->and(collect($low)->firstWhere('product', 'Brass Lamp')['low_level'])->toBe(30);

        $aging = iterator_to_array(app(ReportCatalogue::class)->find('stock_aging')->rows(new ReportFilter('2026-03-20', '2026-03-20')), false);
        expect(array_column($aging, 'product'))->toContain('Brass Lamp')->not->toContain('Cotton Tee');   // the tee has none left, so nothing is sitting
    });
});

describe('the screen', function () {
    it('shows the rows, the filters the report has and the ask-for-a-file button', function () {
        $page = ($this->as)($this->analyst)->get('/admin/reports/payment_methods'.$this->q.'&channel=web')->assertOk();

        $page->assertSee('name="from"', false)->assertSee('name="channel"', false)->assertSee('name="locale"', false)->assertDontSee('name="method"', false)   // a method filter would mean nothing here
            ->assertSee('Ask for a file')->assertSee('1,160.00');
        ($this->as)($this->finance)->get('/admin/reports/cod_aging')->assertOk()->assertDontSee('name="from"', false)->assertSee('580.00');
    });

    it('does not offer the file to someone who may not export', function () {
        $viewer = catalogStaff(['reports.view']);

        ($this->as)($viewer)->get('/admin/reports/sales_by_day'.$this->q)->assertOk()->assertDontSee('Ask for a file');
    });

    it('refuses a bad period', function () {
        ($this->as)($this->analyst)->get('/admin/reports/sales_by_day?from=2026-03-11&to=2026-03-01')->assertSessionHasErrors('to');
        ($this->as)($this->analyst)->get('/admin/reports/sales_by_day?channel=moon')->assertSessionHasErrors('channel');
    });

    it('says when there is nothing to show', function () {
        ($this->as)($this->analyst)->get('/admin/reports/refunds_cancellations?from=2025-01-01&to=2025-01-02')->assertOk()->assertSee('Nothing has been worked out for this period yet.');
    });

    it('shows only the first rows and says so', function () {
        config(['reporting.screen_rows' => 1]);
        ($this->as)($this->analyst)->get('/admin/reports/sales_by_day'.$this->q)->assertOk()->assertSee('first 1 rows');
    });

    it('shows in Bangla with no missing words', function () {
        $this->finance->forceFill(['locale' => 'bn'])->save();
        foreach (array_map(fn ($r) => $r->code(), app(ReportCatalogue::class)->all()) as $code) {
            expect(($this->as)($this->finance)->get("/admin/reports/{$code}".$this->q)->assertOk()->getContent())->not->toContain('reporting::');
        }
        ($this->as)($this->finance)->get('/admin/reports')->assertOk()->assertSee('রিপোর্ট');
        $values = app(ReportValues::class);
        foreach (app(ReportCatalogue::class)->all() as $report) {
            foreach ($report->columns() as $column) {
                expect($values->label($report, $column->key))->not->toBe($column->key, "no words for {$report->code()}.{$column->key}");
            }
        }
    });
});
