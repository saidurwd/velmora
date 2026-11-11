<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Reporting\Reports\ReportCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Phase 10 gate: the back office on ONE MILLION orders. Heavy, so it only runs on request:
 *   RUN_SCALE_TESTS=1 vendor/bin/pest tests/Database/ReportScaleTest.php      (against the throw-away MariaDB 11.4, DB velmora_test)
 *
 * It builds about a year of synthetic orders (1,000,000 orders, 2,000,000 lines, ~950,000 payments, ~30,000 refunds, ~700,000 parcels, 100,000 coupon uses) with
 * single INSERT ... SELECT statements, works every report day out the way the nightly job does, and then checks the budgets of PERFORMANCE_AND_SCALABILITY.md:
 *   - an interactive report answers in 5 s or less (a year, a month and a week), and its queries use indexes;
 *   - an admin list page and the dashboard answer in 1 s or less, and their queries use indexes;
 *   - the two big files (orders, customers) build in the background, so they get a generous ceiling and their time is printed.
 * Timings go to STDERR. Nothing here is a production measurement: it is one request in-process on the development machine, without the web server and network.
 */

const SCALE_ORDERS = 1_000_000;
const SCALE_CHUNK = 100_000;
const SCALE_BASE = '2026-03-19 23:59:59';   // the day before the frozen "now" of reportWorld (2026-03-20 12:00 UTC)

/** Runs one request and returns [response, milliseconds, list of query plans that scan a big table]. */
function scaleTimed(object $t, User $as, string $url, int $bigRows = 50_000): array
{
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        if (str_starts_with(ltrim(strtolower($q->sql)), 'select')) {
            $queries[] = [$q->sql, $q->bindings];
        }
    });
    auth()->forgetGuards();
    $start = (hrtime(true) / 1e9);
    $res = $t->actingAs($as, 'admin')->get($url);
    $ms = ((hrtime(true) / 1e9) - $start) * 1000;

    $scans = [];
    foreach ($queries as [$sql, $bindings]) {
        try {
            foreach (DB::select('EXPLAIN '.$sql, $bindings) as $row) {
                if (($row->type ?? null) === 'ALL' && (int) ($row->rows ?? 0) > $bigRows) {
                    $scans[] = "{$row->table} scanned (~{$row->rows} rows) by: ".mb_substr($sql, 0, 160);
                }
            }
        } catch (Throwable) {
            // not every statement can be explained (for example one that uses a derived table of the framework); the others still count
        }
    }

    return [$res, $ms, $scans];
}

it('keeps the back office within its budgets on a million orders', function () {
    shopWorld($this);
    reportWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware([EnforceSessionPolicy::class, ThrottleRequests::class]);   // the rate limits are not what is measured here
    DB::connection()->disableQueryLog();
    DB::statement('SET SESSION foreign_key_checks = 0');

    $variants = DB::table('product_variants')->pluck('id')->map(fn ($v) => (int) $v)->all();
    expect($variants)->not->toBeEmpty();
    $pick = 'ELT(1 + ((o.id + k.n) % '.count($variants).'), '.implode(',', $variants).')';
    $account = (int) (DB::table('courier_accounts')->value('id') ?? DB::table('courier_accounts')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'courier' => 'fake', 'label' => 'Fake', 'is_active' => 1, 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()]));
    $coupon = DB::table('coupons')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'code' => 'SCALE', 'type' => 'fixed', 'value' => 100, 'scope' => 'all', 'is_active' => true, 'min_spend_minor' => 0, 'usage_limit' => null, 'used_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $base = SCALE_BASE;

    // ---- build the data -------------------------------------------------------------------------------------------------------------------------------------------
    $built = (hrtime(true) / 1e9);
    foreach (range(0, (int) (SCALE_ORDERS / SCALE_CHUNK) - 1) as $c) {
        $lo = $c * SCALE_CHUNK + 1;
        $hi = ($c + 1) * SCALE_CHUNK;
        DB::statement("
            INSERT INTO orders (id, public_id, order_number, contact_name, contact_phone, status, payment_status, channel, locale, subtotal_minor, discount_minor, shipping_minor, cod_fee_minor,
                                tax_minor, grand_total_minor, tax_inclusive, payment_method_code, placed_at, cancelled_at, delivered_at, created_at, updated_at)
            SELECT s.seq, LPAD(CONV(s.seq, 10, 36), 26, '0'), CONCAT('VM-S-', s.seq), 'Scale Customer', CONCAT('+88017', LPAD(s.seq % 250000, 8, '0')),
                   CASE s.seq % 20 WHEN 0 THEN 'cancelled' WHEN 1 THEN 'pending' WHEN 2 THEN 'confirmed' ELSE 'completed' END,
                   CASE WHEN s.seq % 20 IN (0, 1) THEN 'unpaid' ELSE 'paid' END,
                   CASE WHEN s.seq % 10 = 3 THEN 'admin' ELSE 'web' END, CASE WHEN s.seq % 3 = 0 THEN 'bn' ELSE 'en' END,
                   100000 + (s.seq % 50) * 10000, CASE WHEN s.seq % 7 = 0 THEN 5000 ELSE 0 END, 6000, CASE WHEN s.seq % 5 < 3 THEN 1000 ELSE 0 END,
                   0, 100000 + (s.seq % 50) * 10000 - CASE WHEN s.seq % 7 = 0 THEN 5000 ELSE 0 END + 6000 + CASE WHEN s.seq % 5 < 3 THEN 1000 ELSE 0 END, 0,
                   CASE WHEN s.seq % 5 < 3 THEN 'cod' ELSE 'online' END,
                   DATE_SUB('{$base}', INTERVAL ((s.seq % 365) * 86400 + (s.seq % 86399)) SECOND),
                   CASE WHEN s.seq % 20 = 0 THEN DATE_SUB('{$base}', INTERVAL ((s.seq % 365) * 86400 + (s.seq % 86399)) SECOND) END,
                   CASE WHEN s.seq % 20 > 2 THEN LEAST(DATE_ADD(DATE_SUB('{$base}', INTERVAL ((s.seq % 365) * 86400 + (s.seq % 86399)) SECOND), INTERVAL 2 DAY), '{$base}') END,
                   DATE_SUB('{$base}', INTERVAL ((s.seq % 365) * 86400 + (s.seq % 86399)) SECOND), DATE_SUB('{$base}', INTERVAL ((s.seq % 365) * 86400 + (s.seq % 86399)) SECOND)
            FROM seq_{$lo}_to_{$hi} s");
        DB::statement("
            INSERT INTO order_items (order_id, variant_id, product_type, name_snapshot, options_snapshot, sku_snapshot, quantity, unit_price_minor, discount_minor, tax_rate_bp, tax_minor, line_total_minor)
            SELECT o.id, {$pick}, 'physical', '{\"en\":\"Scale item\"}', '[]', 'SCALE', 1, IF(k.n = 1, o.subtotal_minor DIV 2, o.subtotal_minor - o.subtotal_minor DIV 2), 0, 0, 0,
                   IF(k.n = 1, o.subtotal_minor DIV 2, o.subtotal_minor - o.subtotal_minor DIV 2)
            FROM orders o JOIN (SELECT 1 AS n UNION ALL SELECT 2) k WHERE o.id BETWEEN {$lo} AND {$hi}");
        DB::statement("
            INSERT INTO payments (public_id, order_id, method_code, provider, amount_minor, captured_minor, refunded_minor, status, paid_at, created_at, updated_at)
            SELECT LPAD(CONV(o.id + 5000000, 10, 36), 26, '0'), o.id, o.payment_method_code, IF(o.payment_method_code = 'cod', 'cod', 'fake'), o.grand_total_minor, o.grand_total_minor,
                   IF(o.id % 33 = 0, o.grand_total_minor DIV 4, 0), 'paid',
                   LEAST(DATE_ADD(o.placed_at, INTERVAL IF(o.payment_method_code = 'cod', 3 * 86400, 3600) SECOND), '{$base}'), o.placed_at, o.placed_at
            FROM orders o WHERE o.id BETWEEN {$lo} AND {$hi} AND o.status IN ('confirmed', 'completed')");
        DB::statement("
            INSERT INTO refunds (public_id, payment_id, order_id, amount_minor, status, method, reason, reason_code, refund_ref, created_at, updated_at)
            SELECT LPAD(CONV(p.order_id + 9000000, 10, 36), 26, '0'), p.id, p.order_id, p.refunded_minor, 'succeeded', 'manual', 'scale', 'item_returned', CONCAT('RF-', p.order_id),
                   LEAST(DATE_ADD(p.paid_at, INTERVAL 5 DAY), '{$base}'), LEAST(DATE_ADD(p.paid_at, INTERVAL 5 DAY), '{$base}')
            FROM payments p WHERE p.order_id BETWEEN {$lo} AND {$hi} AND p.refunded_minor > 0");
        DB::statement("
            INSERT INTO shipments (public_id, order_id, courier_account_id, courier, status, cod_amount_minor, handed_over_at, delivered_at, created_at, updated_at)
            SELECT LPAD(CONV(o.id + 20000000, 10, 36), 26, '0'), o.id, {$account}, 'fake', 'delivered', IF(o.payment_method_code = 'cod', o.grand_total_minor, 0),
                   DATE_ADD(o.placed_at, INTERVAL 1 DAY), o.delivered_at, o.placed_at, o.placed_at
            FROM orders o WHERE o.id BETWEEN {$lo} AND {$hi} AND o.status = 'completed' AND o.id % 10 < 7");
        DB::statement("
            INSERT INTO coupon_redemptions (coupon_id, order_id, redeemer, amount_minor, redeemed_at)
            SELECT {$coupon}, o.id, CONCAT('scale-', o.id), o.discount_minor, o.placed_at FROM orders o WHERE o.id BETWEEN {$lo} AND {$hi} AND o.id % 10 = 0 AND o.status <> 'cancelled'");
    }
    DB::statement('SET SESSION foreign_key_checks = 1');
    DB::statement('ANALYZE TABLE orders, order_items, payments, refunds, shipments, coupon_redemptions');
    fwrite(STDERR, sprintf("\n  built %s orders, %s lines, %s payments, %s refunds, %s parcels in %.0f s\n", number_format((int) DB::table('orders')->count()), number_format((int) DB::table('order_items')->count()),
        number_format((int) DB::table('payments')->count()), number_format((int) DB::table('refunds')->count()), number_format((int) DB::table('shipments')->count()), (hrtime(true) / 1e9) - $built));
    expect((int) DB::table('orders')->count())->toBe(SCALE_ORDERS);

    // ---- the nightly job: every day of the year ---------------------------------------------------------------------------------------------------------------------
    $days = $this->days->range(CarbonImmutable::parse(SCALE_BASE)->subDays(365)->format('Y-m-d'), CarbonImmutable::parse(SCALE_BASE)->format('Y-m-d'));
    $worst = 0.0;
    $all = (hrtime(true) / 1e9);
    foreach ($days as $day) {
        $one = (hrtime(true) / 1e9);
        ($this->refresh)($day, 'rebuild');
        $worst = max($worst, (hrtime(true) / 1e9) - $one);
    }
    fwrite(STDERR, sprintf("  worked out %d report days in %.0f s (slowest day %.1f s)\n", count($days), (hrtime(true) / 1e9) - $all, $worst));
    expect($worst)->toBeLessThan(60.0);   // one day must stay a small job even when the year is big

    // ---- interactive reports: 5 s ----------------------------------------------------------------------------------------------------------------------------------
    $finance = catalogStaff(['reports.view', 'reports.export', 'reports.finance.view', 'inventory.view', 'orders.view', 'orders.export', 'customers.export', 'dashboard.view', 'payments.view', 'support.view', 'reviews.moderate']);
    $periods = ['year' => '?from=2025-03-21&to=2026-03-19', 'month' => '?from=2026-02-18&to=2026-03-19', 'week' => '?from=2026-03-13&to=2026-03-19'];
    $problems = [];
    foreach (app(ReportCatalogue::class)->all() as $report) {
        if (in_array($report->code(), ['orders_file', 'customers_file'], true)) {
            continue;   // files: below
        }
        foreach ($periods as $name => $query) {
            [$res, $ms, $scans] = scaleTimed($this, $finance, "/admin/reports/{$report->code()}{$query}");
            fwrite(STDERR, sprintf("  report %-26s %-5s %6.0f ms  (status %d)\n", $report->code(), $name, $ms, $res->getStatusCode()));
            if ($res->getStatusCode() !== 200) {
                $problems[] = "{$report->code()} {$name} answered {$res->getStatusCode()}";
            }
            if ($ms > 5000) {
                $problems[] = sprintf('%s %s took %.0f ms (budget 5000)', $report->code(), $name, $ms);
            }
            foreach ($scans as $s) {
                $problems[] = "{$report->code()} {$name}: {$s}";
            }
        }
    }

    // ---- lists and the dashboard: 1 s ------------------------------------------------------------------------------------------------------------------------------
    $some = DB::table('orders')->where('id', 777_777)->first();
    $pages = [
        'orders list' => '/admin/orders',
        'orders list, page 500' => '/admin/orders?page=500',
        'orders list, status filter' => '/admin/orders?status=confirmed',
        'orders list, payment filter' => '/admin/orders?payment=unpaid',
        'dashboard' => '/admin',
        'search by order number' => '/admin/search?q='.$some->order_number,
        'search by phone' => '/admin/search?q='.$some->contact_phone,
        'payments list' => '/admin/payments',
        'reports list' => '/admin/reports',
    ];
    foreach ($pages as $name => $url) {
        [$res, $ms, $scans] = scaleTimed($this, $finance, $url);
        fwrite(STDERR, sprintf("  page   %-26s %6.0f ms  (status %d)\n", $name, $ms, $res->getStatusCode()));
        if ($res->getStatusCode() !== 200) {
            $problems[] = "{$name} answered {$res->getStatusCode()}";
        }
        if ($ms > 1000) {
            $problems[] = sprintf('%s took %.0f ms (budget 1000)', $name, $ms);
        }
        foreach ($scans as $s) {
            $problems[] = "{$name}: {$s}";
        }
    }

    // ---- the two big files (built in the background, so only a ceiling) --------------------------------------------------------------------------------------------------
    foreach (['orders_file' => '?from=2026-02-18&to=2026-03-19', 'customers_file' => '?from=2025-03-21&to=2026-03-19'] as $code => $query) {
        [$res, $ms] = scaleTimed($this, $finance, "/admin/reports/{$code}{$query}");
        fwrite(STDERR, sprintf("  file   %-26s %6.0f ms  (status %d, screen: first rows only)\n", $code, $ms, $res->getStatusCode()));
        if ($res->getStatusCode() !== 200 || $ms > 120_000) {
            $problems[] = sprintf('%s screen answered %d in %.0f ms', $code, $res->getStatusCode(), $ms);
        }
    }

    expect($problems)->toBe([]);
})->skip(! getenv('RUN_SCALE_TESTS'), 'Heavy: run with RUN_SCALE_TESTS=1');
