<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Reporting\Actions\RefreshReportDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* The sales summary screen: who may see it, who may see the money rows, what it says when the figures are incomplete, and that it reads only the summary tables. */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00', 'UTC'));
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->viewer = catalogStaff(['reports.view']);
    $this->finance = catalogStaff(['reports.view', 'reports.finance.view']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->as = fn (User $u) => $this->flushSession()->actingAs($u, 'admin');

    $o = DB::table('orders')->insertGetId([
        'public_id' => strtolower((string) Str::ulid()), 'order_number' => 'VM-SCR-000001', 'contact_name' => 'Test', 'contact_phone' => '+8801712345678', 'locale' => 'en', 'channel' => 'web', 'status' => 'confirmed',
        'currency' => 'BDT', 'subtotal_minor' => 123456, 'discount_minor' => 1000, 'shipping_minor' => 6000, 'cod_fee_minor' => 0, 'tax_minor' => 0, 'grand_total_minor' => 128456, 'tax_inclusive' => false,
        'payment_method_code' => 'online', 'placed_at' => '2026-03-10 06:00:00', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('payments')->insert(['public_id' => strtolower((string) Str::ulid()), 'order_id' => $o, 'method_code' => 'online', 'provider' => 'sslcommerz', 'amount_minor' => 128456, 'currency' => 'BDT',
        'captured_minor' => 128456, 'status' => 'paid', 'paid_at' => '2026-03-10 06:30:00', 'created_at' => '2026-03-10 06:30:00', 'updated_at' => '2026-03-10 06:30:00']);
    app(RefreshReportDay::class)->handle('2026-03-10');
    $this->url = '/admin/reports/sales?from=2026-03-10&to=2026-03-10';
});

describe('who may look', function () {
    it('sends a visitor to the staff sign-in', function () {
        $this->get('/admin/reports/sales')->assertRedirect('/admin/login');
    });

    it('refuses staff without the permission', function () {
        ($this->as)($this->nobody)->get('/admin/reports/sales')->assertForbidden();
    });

    it('shows the sales figures to someone who may look at reports', function () {
        ($this->as)($this->viewer)->get($this->url)->assertOk()
            ->assertSee('Gross sales')->assertSee('1,234.56')->assertSee('Billed total')->assertSee('1,284.56')->assertSee('Average order value');
    });

    it('keeps the money received and given back, and net sales, from someone without finance permission', function () {
        $page = ($this->as)($this->viewer)->get($this->url)->assertOk();

        $page->assertDontSee('Money received')->assertDontSee('Money given back')->assertDontSee('Net sales')->assertSee('finance figures');
    });

    it('shows the finance rows to someone with finance permission', function () {
        ($this->as)($this->finance)->get($this->url)->assertOk()->assertSee('Money received')->assertSee('Money given back')->assertSee('Net sales')->assertSee('1,224.56');   // 123,456 - 1,000 = 122,456 taka*100
    });
});

describe('the period', function () {
    it('defaults to the last 30 days', function () {
        ($this->as)($this->viewer)->get('/admin/reports/sales')->assertOk()->assertSee('value="2026-02-19"', false)->assertSee('value="2026-03-20"', false);
    });

    it('refuses a last day before the first, a period that is too long, and things that are not dates', function () {
        ($this->as)($this->viewer)->get('/admin/reports/sales?from=2026-03-10&to=2026-03-01')->assertSessionHasErrors('to');
        ($this->as)($this->viewer)->get('/admin/reports/sales?from=2024-01-01&to=2026-03-01')->assertSessionHasErrors('to');
        ($this->as)($this->viewer)->get('/admin/reports/sales?from=yesterday')->assertSessionHasErrors('from');
        ($this->as)($this->viewer)->get('/admin/reports/sales?channel=moon')->assertSessionHasErrors('channel');
        ($this->as)($this->viewer)->get('/admin/reports/sales?method='.urlencode("x'; DROP TABLE orders;--"))->assertSessionHasErrors('method');
        ($this->as)($this->viewer)->get('/admin/reports/sales?locale=fr')->assertSessionHasErrors('locale');
    });

    it('narrows to what was asked', function () {
        ($this->as)($this->viewer)->get($this->url.'&channel=admin')->assertOk()->assertDontSee('1,234.56');
        ($this->as)($this->viewer)->get($this->url.'&channel=web&method=online&locale=en')->assertOk()->assertSee('1,234.56');
    });

    it('says plainly when days have not been worked out, and when nothing has', function () {
        ($this->as)($this->viewer)->get('/admin/reports/sales?from=2026-03-08&to=2026-03-12')->assertOk()->assertSee('4 of 5 day(s)')->assertSee('incomplete');
        ($this->as)($this->viewer)->get('/admin/reports/sales?from=2025-01-01&to=2025-01-03')->assertOk()->assertSee('Nothing has been worked out for this period yet.');
    });

    it('lists every day of the period, newest first, with a dash for a day without figures', function () {
        $page = ($this->as)($this->viewer)->get('/admin/reports/sales?from=2026-03-09&to=2026-03-11')->assertOk();

        expect($page->getContent())->toMatch('/11 Mar 2026.*10 Mar 2026.*9 Mar 2026/s');
    });
});

describe('it reads only the summary', function () {
    it('does not touch the order or payment tables', function () {
        $seen = [];
        DB::listen(function ($q) use (&$seen) {
            foreach (['orders', 'order_items', 'payments', 'refunds'] as $table) {
                if (preg_match('/\b(from|join)\s+`?'.$table.'`?\b/i', $q->sql) === 1) {
                    $seen[] = $table;
                }
            }
        });
        ($this->as)($this->finance)->get($this->url)->assertOk();

        expect($seen)->toBe([]);
    });

    it('uses a handful of queries however long the period', function () {
        ($this->as)($this->finance)->get($this->url)->assertOk();   // the first request loads the permissions
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        ($this->as)($this->finance)->get('/admin/reports/sales?from=2025-03-20&to=2026-03-20')->assertOk();
        $year = $count;
        $count = 0;
        ($this->as)($this->finance)->get($this->url)->assertOk();

        expect($year)->toBe($count)->and($count)->toBeLessThan(20);
    });
});

describe('words', function () {
    it('shows in Bangla with no missing words', function () {
        $this->finance->forceFill(['locale' => 'bn'])->save();
        $page = ($this->as)($this->finance)->get($this->url)->assertOk();

        $page->assertSee('বিক্রয়ের সারসংক্ষেপ')->assertSee('মোট বিক্রয়')->assertSee('নিট বিক্রয়');
        expect($page->getContent())->not->toContain('reporting::');
    });

    it('is in the staff menu only for those who may look', function () {
        ($this->as)($this->viewer)->get($this->url)->assertSee('href="'.url('/admin/reports/sales').'"', false);
        ($this->as)($this->nobody)->get('/admin/catalog/categories')->assertOk()->assertDontSee(url('/admin/reports/sales'), false);
    });
});
