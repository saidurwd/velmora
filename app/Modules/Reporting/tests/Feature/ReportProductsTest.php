<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Reports\ReportCatalogue;
use App\Modules\Reporting\Services\Ratio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Sales by product, category and brand, best sellers and the tax summary, against a scenario worked out by hand. Orders and their lines are written straight into the ledger
 * tables, and every report is compared with the hand figures AND with plain sums taken from those tables. Figures in paisa, day 10 March 2026 (Dhaka).
 *
 *   O1 website, English, online   : 3 tees @650.00 (50.00 off the line, 1 returned) + 2 lamps @2,300.00            goods 6,550.00  discount 50.00
 *   O2 staff, Bangla, cash        : 1 tee + 1 e-book @350.00                                                      goods 1,000.00
 *   O3 website, CANCELLED         : 10 lamps                                                                      (never a sale)
 *   O4 website, English, online, prices include 15% tax: 2 tees (tax 169.57 inside) + delivery 60.00 (tax 7.83 inside)
 *   O5 website, English, online, tax added on top: 2 lamps, tax 15% = 690.00
 *   tee: category Clothing, brand Velmora Basics.  lamp: category Home, no brand.  e-book: no category, brand Velmora Basics.
 */

beforeEach(function () {
    shopWorld($this);
    reportWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->finance = catalogStaff(['reports.view', 'reports.export', 'reports.finance.view']);
    $this->analyst = catalogStaff(['reports.view', 'reports.export']);
    $this->as = fn (User $u) => $this->flushSession()->actingAs($u, 'admin');

    $actor = Actor::system('t');
    $clothing = app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', 'Clothing', 'clothing'), new TranslationInput('bn', 'পোশাক')]);
    $home = app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', 'Home', 'home'), new TranslationInput('bn', 'ঘর')]);
    $brand = DB::table('brands')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'created_at' => now(), 'updated_at' => now()]);
    foreach (['en' => 'Velmora Basics', 'bn' => 'ভেলমোরা বেসিকস'] as $locale => $name) {
        DB::table('brand_translations')->insert(['brand_id' => $brand, 'locale' => $locale, 'name' => $name, 'slug' => 'velmora-basics-'.$locale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $productOf = fn (array $v): int => (int) DB::table('product_variants')->where('id', $v['variantId'])->value('product_id');
    DB::table('product_categories')->delete();
    DB::table('product_categories')->insert([['product_id' => $productOf($this->tee), 'category_id' => $clothing->id, 'is_primary' => true], ['product_id' => $productOf($this->lamp), 'category_id' => $home->id, 'is_primary' => true]]);
    DB::table('products')->whereIn('id', [$productOf($this->tee), $productOf($this->ebook)])->update(['brand_id' => $brand]);
    $this->teeProduct = $productOf($this->tee);

    /** A line on an order, straight into the ledger table. */
    $this->item = function (int $orderId, array $variant, int $qty, int $unit, int $discount = 0, int $returned = 0, int $rateBp = 0, int $tax = 0, ?string $sku = null): void {
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'variant_id' => $variant['variantId'], 'product_type' => 'physical', 'name_snapshot' => json_encode(['en' => 'x']), 'sku_snapshot' => $sku ?? (string) DB::table('product_variants')->where('id', $variant['variantId'])->value('sku'),
            'quantity' => $qty, 'unit_price_minor' => $unit, 'discount_minor' => $discount, 'tax_rate_bp' => $rateBp, 'tax_minor' => $tax, 'line_total_minor' => $qty * $unit - $discount, 'fulfilled_qty' => $returned > 0 ? $qty : 0,
            'returned_qty' => $returned, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $o1 = ($this->order)(['subtotal_minor' => 655000, 'discount_minor' => 5000, 'grand_total_minor' => 650000]);
    ($this->item)($o1, $this->tee, 3, 65000, 5000, 1);
    ($this->item)($o1, $this->lamp, 2, 230000);
    $o2 = ($this->order)(['channel' => 'admin', 'locale' => 'bn', 'payment_method_code' => 'cod', 'subtotal_minor' => 100000, 'grand_total_minor' => 100000]);
    ($this->item)($o2, $this->tee, 1, 65000);
    ($this->item)($o2, $this->ebook, 1, 35000);
    $o3 = ($this->order)(['status' => 'cancelled', 'cancelled_at' => '2026-03-10 09:00:00', 'subtotal_minor' => 2300000, 'grand_total_minor' => 2300000]);
    ($this->item)($o3, $this->lamp, 10, 230000);
    $o4 = ($this->order)(['subtotal_minor' => 130000, 'shipping_minor' => 6000, 'tax_minor' => 16957, 'tax_inclusive' => true, 'grand_total_minor' => 136000]);
    ($this->item)($o4, $this->tee, 2, 65000, 0, 0, 1500, 16957);
    DB::table('order_tax_lines')->insert(['order_id' => $o4, 'kind' => 'shipping', 'rate_bp' => 1500, 'taxable_minor' => 5217, 'tax_minor' => 783, 'created_at' => now()]);
    $o5 = ($this->order)(['subtotal_minor' => 460000, 'tax_minor' => 69000, 'grand_total_minor' => 529000]);
    ($this->item)($o5, $this->lamp, 2, 230000, 0, 0, 1500, 69000);
    ($this->work)('2026-03-10', '2026-03-10');

    $this->f = new ReportFilter('2026-03-10', '2026-03-10');
    $this->rowsOf = fn (string $code, ?ReportFilter $f = null): array => iterator_to_array(app(ReportCatalogue::class)->find($code)->rows($f ?? $this->f), false);
});

describe('sales by product', function () {
    it('adds up each product from the lines, leaving out the cancelled order', function () {
        $rows = ($this->rowsOf)('sales_by_product');
        $by = array_column($rows, null, 'product');

        expect(array_column($rows, 'product'))->toBe(['Brass Lamp', 'Cotton Tee', 'Cooking e-book'])   // biggest sales first
            ->and($by['Cotton Tee'])->toMatchArray(['category' => 'Clothing', 'brand' => 'Velmora Basics', 'variants' => 1, 'units_sold' => 6, 'returned_units' => 1, 'return_rate' => 1667,
                'gross_sales' => 390000, 'discounts' => 5000, 'sales_after_discounts' => 385000])
            ->and($by['Brass Lamp'])->toMatchArray(['category' => 'Home', 'brand' => 'No brand', 'units_sold' => 4, 'returned_units' => 0, 'return_rate' => 0, 'gross_sales' => 920000, 'sales_after_discounts' => 920000])
            ->and($by['Cooking e-book'])->toMatchArray(['category' => 'Uncategorised', 'brand' => 'Velmora Basics', 'units_sold' => 1, 'gross_sales' => 35000]);
    });

    it('equals the sales summary and plain sums from the ledger tables', function () {
        $rows = ($this->rowsOf)('sales_by_product');
        $summary = ($this->numbers)('2026-03-10', '2026-03-10');
        $live = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')->where('o.status', '<>', 'cancelled');

        expect(array_sum(array_column($rows, 'gross_sales')))->toBe($summary['gross_sales'])->and(array_sum(array_column($rows, 'discounts')))->toBe($summary['discounts'])
            ->and(array_sum(array_column($rows, 'sales_after_discounts')))->toBe($summary['sales_after_discounts'])->and(array_sum(array_column($rows, 'sales_after_discounts')))->toBe(1340000)
            ->and(array_sum(array_column($rows, 'units_sold')))->toBe((int) (clone $live)->sum('i.quantity'))
            ->and(array_sum(array_column($rows, 'returned_units')))->toBe((int) (clone $live)->sum('i.returned_qty'))
            ->and(array_sum(array_column($rows, 'gross_sales')))->toBe((int) (clone $live)->selectRaw('SUM(i.quantity * i.unit_price_minor) AS s')->value('s'));
    });

    it('can be narrowed by channel, payment method and language, and by day', function () {
        $admin = ($this->rowsOf)('sales_by_product', new ReportFilter('2026-03-10', '2026-03-10', 'admin'));
        $cod = ($this->rowsOf)('sales_by_product', new ReportFilter('2026-03-10', '2026-03-10', null, 'cod'));
        $bn = ($this->rowsOf)('sales_by_product', new ReportFilter('2026-03-10', '2026-03-10', null, null, 'bn'));

        expect(array_column($admin, 'units_sold', 'product'))->toBe(['Cotton Tee' => 1, 'Cooking e-book' => 1])->and($cod)->toBe($admin)->and($bn)->toBe($admin)
            ->and(($this->rowsOf)('sales_by_product', new ReportFilter('2026-03-11', '2026-03-12')))->toBe([])
            ->and(array_column(($this->rowsOf)('sales_by_product', new ReportFilter('2026-03-10', '2026-03-10', 'web', 'online', 'en')), 'units_sold', 'product'))->toBe(['Brass Lamp' => 4, 'Cotton Tee' => 5]);
    });

    it('adds the variants of one product together', function () {
        $second = ['variantId' => DB::table('product_variants')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'product_id' => $this->teeProduct, 'sku' => 'TEE-1-L', 'option_signature' => 'size-l', 'created_at' => now(), 'updated_at' => now()])];
        $o = ($this->order)(['subtotal_minor' => 65000, 'grand_total_minor' => 65000]);
        ($this->item)($o, $second, 1, 65000);
        ($this->refresh)('2026-03-10');
        $tee = array_column(($this->rowsOf)('sales_by_product'), null, 'product')['Cotton Tee'];

        expect($tee['variants'])->toBe(2)->and($tee['units_sold'])->toBe(7)->and(count(($this->rowsOf)('sales_by_product')))->toBe(3);
    });

    it('shows a product that was removed from the catalogue under the code written on the order', function () {
        DB::table('report_daily_products')->insert(['day' => '2026-03-10', 'variant_id' => 999999, 'sku' => 'GONE-1', 'channel' => 'web', 'payment_method' => 'online', 'locale' => 'en', 'currency' => 'BDT', 'orders' => 1,
            'units' => 2, 'returned_units' => 0, 'gross_minor' => 100, 'discounts_minor' => 0, 'refreshed_at' => now()]);
        $rows = array_column(($this->rowsOf)('sales_by_product'), null, 'product');

        expect($rows)->toHaveKey('GONE-1')->and($rows['GONE-1']['category'])->toBe('Uncategorised')->and($rows['GONE-1']['brand'])->toBe('No brand')->and($rows['GONE-1']['units_sold'])->toBe(2);
    });

    it('falls back to the default language for a name that has no translation', function () {
        DB::table('product_translations')->where('product_id', $this->teeProduct)->where('locale', 'bn')->delete();
        DB::table('category_translations')->where('name', 'পোশাক')->delete();
        DB::table('brand_translations')->where('locale', 'bn')->delete();
        app()->setLocale('bn');
        $rows = array_column(($this->rowsOf)('sales_by_product'), null, 'product');

        expect($rows)->toHaveKey('Cotton Tee')->and($rows['Cotton Tee']['category'])->toBe('Clothing')->and($rows['Cotton Tee']['brand'])->toBe('Velmora Basics');
    });

    it('shows names in the language asked for', function () {
        app()->setLocale('bn');
        $bn = ($this->rowsOf)('sales_by_product');

        expect(array_column($bn, 'category'))->toContain('পোশাক')->and(array_column($bn, 'brand'))->toContain('ভেলমোরা বেসিকস')->and(array_column($bn, 'category'))->toContain('ক্যাটেগরি নেই');
    });
});

describe('best sellers', function () {
    it('ranks by units, then by sales, and counts from one', function () {
        $rows = ($this->rowsOf)('best_sellers');

        expect(array_column($rows, 'product'))->toBe(['Cotton Tee', 'Brass Lamp', 'Cooking e-book'])->and(array_column($rows, 'rank'))->toBe([1, 2, 3])->and(array_column($rows, 'units_sold'))->toBe([6, 4, 1])
            ->and($rows[0]['return_rate'])->toBe(1667);
    });

    it('stops at twenty', function () {
        $actor = Actor::system('t');
        $o = ($this->order)(['subtotal_minor' => 25 * 100, 'grand_total_minor' => 25 * 100]);
        foreach (range(1, 25) as $i) {
            ($this->item)($o, $this->tee, 1, 100, 0, 0, 0, 0, 'GHOST-'.$i);   // same variant, so this adds to the tee; ghosts below are separate products
            DB::table('report_daily_products')->insert(['day' => '2026-03-10', 'variant_id' => 900000 + $i, 'sku' => 'GHOST-'.$i, 'channel' => 'web', 'payment_method' => 'online', 'locale' => 'en', 'currency' => 'BDT', 'orders' => 1,
                'units' => 1, 'returned_units' => 0, 'gross_minor' => 100, 'discounts_minor' => 0, 'refreshed_at' => now()]);
        }

        expect($actor)->not->toBeNull()->and(count(($this->rowsOf)('best_sellers')))->toBe(20)->and(count(($this->rowsOf)('sales_by_product')))->toBeGreaterThan(20);
    });
});

describe('sales by category and brand', function () {
    it('splits the same total, each product in one category', function () {
        $rows = ($this->rowsOf)('sales_by_category');

        expect(array_column($rows, 'category'))->toBe(['Home', 'Clothing', 'Uncategorised'])->and(array_column($rows, 'sales_after_discounts'))->toBe([920000, 385000, 35000])
            ->and(array_column($rows, 'share'))->toBe([6866, 2873, 261])->and(array_sum(array_column($rows, 'share')))->toBe(10000)
            ->and(array_sum(array_column($rows, 'sales_after_discounts')))->toBe(1340000)->and(array_column($rows, 'products'))->toBe([1, 1, 1]);
    });

    it('prefers the primary category over a lower-numbered one', function () {
        $clothing = (int) DB::table('category_translations')->where('name', 'Clothing')->value('category_id');
        $lampProduct = (int) DB::table('product_variants')->where('id', $this->lamp['variantId'])->value('product_id');
        DB::table('product_categories')->insert(['product_id' => $lampProduct, 'category_id' => $clothing, 'is_primary' => false]);   // Clothing was made first, so it has the lower number, but Home is the lamp's main one
        $rows = array_column(($this->rowsOf)('sales_by_category'), 'sales_after_discounts', 'category');

        expect($rows)->toBe(['Home' => 920000, 'Clothing' => 385000, 'Uncategorised' => 35000]);
    });

    it('falls back to the product with the lowest-numbered category when none is marked primary', function () {
        $clothing = (int) DB::table('category_translations')->where('name', 'Clothing')->value('category_id');
        $home = (int) DB::table('category_translations')->where('name', 'Home')->value('category_id');
        $lampProduct = (int) DB::table('product_variants')->where('id', $this->lamp['variantId'])->value('product_id');
        DB::table('product_categories')->where('product_id', $lampProduct)->delete();
        DB::table('product_categories')->insert([['product_id' => $lampProduct, 'category_id' => $home, 'is_primary' => false], ['product_id' => $lampProduct, 'category_id' => $clothing, 'is_primary' => false]]);
        $rows = array_column(($this->rowsOf)('sales_by_category'), 'sales_after_discounts', 'category');

        expect($rows)->toBe(['Clothing' => 1305000, 'Uncategorised' => 35000]);   // the lamp joins the lowest-numbered one, once
    });

    it('puts a product only in its primary category even when it has several', function () {
        $other = (int) DB::table('categories')->orderByDesc('id')->value('id');
        $home = (int) DB::table('category_translations')->where('name', 'Home')->value('category_id');
        DB::table('product_categories')->insert(['product_id' => $this->teeProduct, 'category_id' => $home, 'is_primary' => false]);   // the tee is also filed under Home, not as its main one
        $rows = array_column(($this->rowsOf)('sales_by_category'), 'sales_after_discounts', 'category');

        expect($rows)->toBe(['Home' => 920000, 'Clothing' => 385000, 'Uncategorised' => 35000])->and(array_sum($rows))->toBe(1340000)->and($other)->toBeInt();
    });

    it('splits by brand with a row for products without one', function () {
        $rows = ($this->rowsOf)('sales_by_brand');

        expect(array_column($rows, 'brand'))->toBe(['No brand', 'Velmora Basics'])->and(array_column($rows, 'sales_after_discounts'))->toBe([920000, 420000])->and(array_column($rows, 'share'))->toBe([6866, 3134])
            ->and(array_column($rows, 'units_sold'))->toBe([4, 7])->and(array_column($rows, 'products'))->toBe([1, 2]);
    });
});

describe('tax summary', function () {
    it('shows the tax billed per charge and rate, and a total that equals the ledgers', function () {
        $rows = ($this->rowsOf)('tax_summary');

        expect($rows)->toHaveCount(5)
            ->and($rows[0])->toMatchArray(['charge' => 'Goods', 'rate' => 0, 'prices' => 'Exclude tax', 'lines' => 4, 'taxable' => 750000, 'tax' => 0])
            ->and($rows[1])->toMatchArray(['charge' => 'Goods', 'rate' => 1500, 'prices' => 'Exclude tax', 'lines' => 1, 'taxable' => 460000, 'tax' => 69000])
            ->and($rows[2])->toMatchArray(['charge' => 'Goods', 'rate' => 1500, 'prices' => 'Include tax', 'lines' => 1, 'taxable' => 113043, 'tax' => 16957])   // the price without the tax inside it
            ->and($rows[3])->toMatchArray(['charge' => 'Delivery', 'rate' => 1500, 'prices' => 'Include tax', 'lines' => 1, 'taxable' => 5217, 'tax' => 783])
            ->and($rows[4])->toMatchArray(['charge' => 'Total', 'rate' => null, 'lines' => 7, 'taxable' => 1328260, 'tax' => 86740]);
        $ledger = (int) DB::table('orders')->where('status', '<>', 'cancelled')->sum('tax_minor') + (int) DB::table('order_tax_lines as t')->join('orders as o', 'o.id', '=', 't.order_id')->where('o.status', '<>', 'cancelled')->sum('t.tax_minor');
        expect($rows[4]['tax'])->toBe($ledger)->and($ledger)->toBe(((int) ($this->numbers)('2026-03-10', '2026-03-10')['tax']) + 783);   // the sales summary's Tax is the goods tax; the delivery tax is on top
    });

    it('is a total of nothing when there was no tax', function () {
        $rows = ($this->rowsOf)('tax_summary', new ReportFilter('2026-03-11', '2026-03-12'));

        expect($rows)->toBe([['charge' => 'Total', 'rate' => null, 'prices' => '', 'lines' => 0, 'taxable' => 0, 'tax' => 0]]);
    });

    it('is for finance only', function () {
        ($this->as)($this->analyst)->get('/admin/reports/tax_summary')->assertForbidden();
        ($this->as)($this->finance)->get('/admin/reports/tax_summary?from=2026-03-10&to=2026-03-10')->assertOk()->assertSee('Tax summary')->assertSee('Total')->assertSee('867.40')->assertSee('not a tax return');
    });
});

describe('what is left out', function () {
    it('leaves a cancelled order out of the delivery tax too', function () {
        $o = ($this->order)(['status' => 'cancelled', 'cancelled_at' => '2026-03-10 09:00:00', 'subtotal_minor' => 1000, 'shipping_minor' => 600, 'grand_total_minor' => 1600]);
        DB::table('order_tax_lines')->insert(['order_id' => $o, 'kind' => 'shipping', 'rate_bp' => 1500, 'taxable_minor' => 522, 'tax_minor' => 78, 'created_at' => now()]);
        ($this->refresh)('2026-03-10');
        $total = collect(($this->rowsOf)('tax_summary'))->last();

        expect($total['tax'])->toBe(86740)->and($total['lines'])->toBe(7);
    });

    it('adds only the shop currency into product sales and tax', function () {
        $o = ($this->order)(['currency' => 'USD', 'subtotal_minor' => 10000, 'grand_total_minor' => 10100, 'tax_minor' => 100, 'tax_inclusive' => false]);
        ($this->item)($o, $this->lamp, 5, 2000, 0, 0, 500, 100);
        ($this->refresh)('2026-03-10');

        expect(array_sum(array_column(($this->rowsOf)('sales_by_product'), 'units_sold')))->toBe(11)
            ->and(collect(($this->rowsOf)('tax_summary'))->last()['tax'])->toBe(86740)
            ->and(DB::table('report_daily_products')->where('currency', 'USD')->sum('units'))->toEqual(5)
            ->and(DB::table('report_daily_tax')->where('currency', 'USD')->sum('tax_minor'))->toEqual(100);
    });
});

describe('days and refreshing', function () {
    it('puts a line on the Dhaka day of its order', function () {
        $o = ($this->order)(['placed_at' => '2026-03-10 18:00:00', 'subtotal_minor' => 65000, 'grand_total_minor' => 65000]);   // midnight: the 11th
        ($this->item)($o, $this->tee, 1, 65000);
        ($this->refresh)('2026-03-10');
        ($this->refresh)('2026-03-11');

        expect(array_sum(array_column(($this->rowsOf)('sales_by_product', new ReportFilter('2026-03-11', '2026-03-11')), 'units_sold')))->toBe(1)
            ->and(array_sum(array_column(($this->rowsOf)('sales_by_product'), 'units_sold')))->toBe(11);
    });

    it('gives the same rows when a day is worked out again, and replaces them when the lines changed', function () {
        $before = DB::table('report_daily_products')->orderBy('id')->get(['variant_id', 'units', 'gross_minor'])->toJson();
        ($this->refresh)('2026-03-10');
        expect(DB::table('report_daily_products')->orderBy('id')->get(['variant_id', 'units', 'gross_minor'])->toJson())->toBe($before);

        DB::table('orders')->where('contact_name', 'Test')->where('status', 'cancelled')->update(['status' => 'confirmed', 'cancelled_at' => null]);   // the cancelled order is revived
        ($this->refresh)('2026-03-10');
        $lamp = array_column(($this->rowsOf)('sales_by_product'), null, 'product')['Brass Lamp'];

        expect($lamp['units_sold'])->toBe(14);
    });

    it('has indexes that serve the daily and range reads', function () {
        expect(Schema::hasIndex('report_daily_products', ['day', 'variant_id', 'channel', 'payment_method', 'locale', 'currency'], 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('report_daily_tax', ['day', 'kind', 'rate_bp', 'currency', 'tax_inclusive'], 'unique'))->toBeTrue();
    });
});

describe('the screens', function () {
    it('open for the reports, show names and figures, and read only the summary tables', function () {
        $seen = [];
        DB::listen(function ($q) use (&$seen) {
            foreach (['orders', 'order_items', 'order_tax_lines'] as $table) {
                if (preg_match('/\b(from|join)\s+`?'.$table.'`?\b/i', $q->sql) === 1) {
                    $seen[] = $table;
                }
            }
        });
        foreach (['best_sellers' => 'Brass Lamp', 'sales_by_product' => 'Brass Lamp', 'sales_by_category' => 'Clothing', 'sales_by_brand' => 'Velmora Basics'] as $code => $word) {
            ($this->as)($this->analyst)->get("/admin/reports/{$code}?from=2026-03-10&to=2026-03-10")->assertOk()->assertSee($word, false)->assertSee('9,200.00');
        }

        expect($seen)->toBe([]);
    });

    it('show the percentages and shares in two decimals', function () {
        ($this->as)($this->analyst)->get('/admin/reports/sales_by_category?from=2026-03-10&to=2026-03-10')->assertOk()->assertSee('68.66%')->assertSee('28.73%')->assertSee('2.61%');
        ($this->as)($this->analyst)->get('/admin/reports/best_sellers?from=2026-03-10&to=2026-03-10')->assertOk()->assertSee('16.67%');
    });

    it('use a handful of queries however many products there are', function () {
        ($this->as)($this->analyst)->get('/admin/reports/sales_by_product?from=2026-03-10&to=2026-03-10')->assertOk();
        foreach (range(1, 30) as $i) {
            DB::table('report_daily_products')->insert(['day' => '2026-03-10', 'variant_id' => 800000 + $i, 'sku' => 'MANY-'.$i, 'channel' => 'web', 'payment_method' => 'online', 'locale' => 'en', 'currency' => 'BDT', 'orders' => 1,
                'units' => 1, 'returned_units' => 0, 'gross_minor' => 100, 'discounts_minor' => 0, 'refreshed_at' => now()]);
        }
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        ($this->as)($this->analyst)->get('/admin/reports/sales_by_product?from=2026-03-10&to=2026-03-10')->assertOk()->assertSee('MANY-30');

        expect($count)->toBeLessThan(20);
    });

    it('writes the same rows into a file as it shows', function () {
        Storage::fake('private');
        config(['reporting.exports.disk' => 'private']);
        ($this->as)($this->analyst)->post('/admin/reports/sales_by_category/export', ['from' => '2026-03-10', 'to' => '2026-03-10'])->assertRedirect();
        $e = DB::table('report_exports')->first();
        $lines = preg_split('/\r?\n/', trim(str_replace("\xEF\xBB\xBF", '', (string) Storage::disk('private')->get((string) $e->file_key))));

        expect($lines[0])->toBe('Category,Products,"Units sold","Units returned","Gross sales",Discounts,"Sales after discounts","Share of billed"')
            ->and($lines[1])->toBe('Home,1,4,0,9200.00,0.00,9200.00,68.66')->and($lines[3])->toBe('Uncategorised,1,1,0,350.00,0.00,350.00,2.61');
    });

    it('show in Bangla with no missing words', function () {
        $this->finance->forceFill(['locale' => 'bn'])->save();
        foreach (['best_sellers', 'sales_by_product', 'sales_by_category', 'sales_by_brand', 'tax_summary'] as $code) {
            expect(($this->as)($this->finance->fresh())->get("/admin/reports/{$code}?from=2026-03-10&to=2026-03-10")->assertOk()->getContent())->not->toContain('reporting::');
        }
    });
});

describe('the share formula', function () {
    it('rounds to the nearest basis point and never divides by zero', function () {
        expect(Ratio::basisPoints(1, 6))->toBe(1667)->and(Ratio::basisPoints(1, 3))->toBe(3333)->and(Ratio::basisPoints(2, 3))->toBe(6667)
            ->and(Ratio::basisPoints(5, 0))->toBe(0)->and(Ratio::basisPoints(0, 9))->toBe(0)->and(Ratio::basisPoints(7, 7))->toBe(10000);
    });
});
