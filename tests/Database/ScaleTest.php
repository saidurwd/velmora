<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveTaxonomyEntity;
use App\Modules\Catalog\DTOs\ListingFilters;
use App\Modules\Catalog\DTOs\ListingScope;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Catalog\Services\Storefront\ListingQuery;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Search\Contracts\CatalogSearch;
use Illuminate\Support\Facades\DB;

/*
 * Scale check (Phase 4 gate): 12,000 live products, bilingual. Heavy, so it only runs on request:
 *   RUN_SCALE_TESTS=1 vendor/bin/pest tests/Database/ScaleTest.php
 * It proves the storefront queries use their indexes (no full scans of the big tables) and stay fast. Timings are printed.
 */

const SCALE_PRODUCTS = 12000;

function scaleSeed(): array
{
    $actor = Actor::system('scale');
    $now = now('UTC')->format('Y-m-d H:i:s.u');

    $categories = [];
    foreach (range(1, 40) as $i) {
        $categories[] = app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', "Category {$i}", "category-{$i}"), new TranslationInput('bn', "ক্যাটাগরি {$i}")])->id;
    }
    $brands = [];
    foreach (range(1, 25) as $i) {
        $brands[] = app(SaveTaxonomyEntity::class)->handle($actor, Brand::class, null, [new TranslationInput('en', "Brand {$i}", "brand-{$i}"), new TranslationInput('bn', "ব্র্যান্ড {$i}")])->id;
    }
    $priceList = (int) DB::table('price_lists')->where('code', 'retail')->value('id');
    $words = [['Cotton', 'Silk', 'Leather', 'Jute', 'Steel', 'Cane', 'Linen', 'Wool'], ['Panjabi', 'Saree', 'Wallet', 'Bag', 'Sandal', 'Lamp', 'Bottle', 'Mat', 'Shirt', 'Towel']];
    $bn = [['সুতির', 'সিল্ক', 'চামড়ার', 'পাটের', 'স্টিলের', 'বেতের', 'লিনেন', 'উলের'], ['পাঞ্জাবি', 'শাড়ি', 'মানিব্যাগ', 'ব্যাগ', 'স্যান্ডেল', 'ল্যাম্প', 'বোতল', 'পাটি', 'শার্ট', 'তোয়ালে']];

    mt_srand(42);
    foreach (array_chunk(range(1, SCALE_PRODUCTS), 1000) as $chunk) {
        $products = $variants = $trans = $links = $prices = $listing = $index = $skus = [];
        foreach ($chunk as $n) {
            $a = mt_rand(0, 7);
            $b = mt_rand(0, 9);
            $name = "{$words[0][$a]} {$words[1][$b]} {$n}";
            $nameBn = "{$bn[0][$a]} {$bn[1][$b]} {$n}";
            $slug = strtolower(str_replace(' ', '-', $name));
            $brand = $brands[$n % 25];
            $cat = $categories[$n % 40];
            $price = mt_rand(1, 400) * 5000;
            $type = $n % 100 === 0 ? 'digital' : 'physical';
            $at = now('UTC')->subMinutes($n)->format('Y-m-d H:i:s.u');
            $sku = "SKU-{$n}";

            $products[] = ['id' => $n, 'public_id' => strtolower(substr(md5((string) $n), 0, 26)), 'product_type' => $type, 'brand_id' => $brand, 'tax_class_code' => 'standard', 'status' => 'published', 'published_at' => $at, 'created_at' => $at, 'updated_at' => $at];
            $variants[] = ['id' => $n, 'public_id' => strtolower(substr(md5("v{$n}"), 0, 26)), 'product_id' => $n, 'sku' => $sku, 'is_default' => 1, 'track_inventory' => $type === 'physical' ? 1 : 0, 'option_signature' => '', 'status' => 'active', 'created_at' => $at, 'updated_at' => $at];
            $trans[] = ['product_id' => $n, 'locale' => 'en', 'name' => $name, 'slug' => $slug, 'created_at' => $at, 'updated_at' => $at];
            $trans[] = ['product_id' => $n, 'locale' => 'bn', 'name' => $nameBn, 'slug' => $slug.'-bn', 'created_at' => $at, 'updated_at' => $at];
            $links[] = ['product_id' => $n, 'category_id' => $cat, 'is_primary' => 1];
            $prices[] = ['variant_id' => $n, 'price_list_id' => $priceList, 'amount_minor' => $price, 'currency' => 'BDT', 'created_at' => $at, 'updated_at' => $at];
            $listing[] = ['product_id' => $n, 'product_type' => $type, 'brand_id' => $brand, 'primary_category_id' => $cat, 'min_price_minor' => $price, 'max_price_minor' => $price, 'on_sale' => 0, 'in_stock' => $n % 7 === 0 ? 0 : 1, 'popularity' => $n % 50 === 0 ? 100 : 0, 'published_at' => $at, 'refreshed_at' => $at];
            foreach (['en' => [$name, $slug, $name], 'bn' => [$nameBn, $slug.'-bn', $nameBn.' '.$name]] as $locale => [$display, $s, $body]) {
                $index[] = ['product_id' => $n, 'locale' => $locale, 'title' => mb_strtolower($display), 'body' => mb_strtolower($body.' '.$sku), 'display_name' => $display, 'slug' => $s, 'created_at' => $at, 'updated_at' => $at];
            }
            $skus[] = ['product_id' => $n, 'sku_norm' => strtolower(str_replace('-', '', $sku))];
        }
        DB::table('products')->insert($products);
        DB::table('product_variants')->insert($variants);
        DB::table('product_translations')->insert($trans);
        DB::table('product_categories')->insert($links);
        DB::table('prices')->insert($prices);
        DB::table('catalog_listing')->insert($listing);
        DB::table('search_index')->insert($index);
        DB::table('search_skus')->insert($skus);
    }

    return [$categories, $brands];
}

it('keeps browsing and search fast and index-backed with 12,000 products', function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class]);
    $started = microtime(true);
    [$categories] = scaleSeed();
    fwrite(STDERR, sprintf("\n  seeded %d products in %.1fs\n", SCALE_PRODUCTS, microtime(true) - $started));

    $query = app(ListingQuery::class);
    $tree = app(CategoryTree::class);
    $category = Category::findOrFail($categories[3]);
    $cases = [ // [scope, filters, (optional) a scan is the right plan because the filter keeps ~all rows]
        'shop, newest' => [ListingScope::all(), new ListingFilters],
        'shop, price low→high' => [ListingScope::all(), new ListingFilters(sort: 'price_asc')],
        'shop, popular' => [ListingScope::all(), new ListingFilters(sort: 'popular')],
        'shop, page 40' => [ListingScope::all(), new ListingFilters(page: 40)],
        'category' => [ListingScope::category($tree->subtreeIds($category)), new ListingFilters],
        'brand page' => [ListingScope::brand((int) DB::table('products')->where('id', 7)->value('brand_id')), new ListingFilters(sort: 'price_asc')],
        'brand filter' => [ListingScope::all(), new ListingFilters(brands: ['brand-3', 'brand-9'])],
        'price range' => [ListingScope::all(), new ListingFilters(minMinor: 200000, maxMinor: 600000, sort: 'price_asc')],
        'in stock only' => [ListingScope::all(), new ListingFilters(inStock: true), true],
        'digital + in stock' => [ListingScope::all(), new ListingFilters(types: ['digital'], inStock: true)],
    ];

    foreach ($cases as $label => $case) {
        [$scope, $filters] = $case;
        $scanOk = $case[2] ?? false;
        DB::flushQueryLog();
        DB::enableQueryLog();
        $t = microtime(true);
        $page = $query->paginate($scope, $filters, 'en');
        $ms = (microtime(true) - $t) * 1000;
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        expect($page->items())->not->toBeEmpty();
        foreach ($log as $q) {
            foreach (DB::select('EXPLAIN '.$q['query'], $q['bindings']) as $step) {
                // no full scan of a big table; reading a few dozen rows of a small lookup table (brands, languages) is fine
                $bigScan = ! ($scanOk ?? false) && strtolower((string) $step->type) === 'all' && (int) $step->rows > 500;
                expect($bigScan)->toBeFalse("{$label}: full scan of {$step->table} ({$step->rows} rows) in: {$q['query']}");
            }
        }
        fwrite(STDERR, sprintf("  %-24s %6.0f ms  (%d queries, %d results)\n", $label, $ms, count($log), $page->total()));
        expect($ms)->toBeLessThan(1500, $label); // generous: CI machines and busy laptops vary; the EXPLAIN checks above are the real guard
    }

    $search = app(CatalogSearch::class);
    foreach (['cotton panjabi' => 'en', 'সুতির পাঞ্জাবি' => 'bn', 'sku-4711' => 'en', 'wallet' => 'en', 'ফোন' => 'bn', 'panjabbi' => 'en'] as $q => $locale) {
        $t = microtime(true);
        $result = $search->search($q, $locale);
        $ms = (microtime(true) - $t) * 1000;
        fwrite(STDERR, sprintf("  search %-18s %6.0f ms  (%d results)\n", $q, $ms, count($result->productIds)));
        expect($ms)->toBeLessThan(2500, $q);
    }

    $plan = DB::select("EXPLAIN SELECT product_id FROM search_index WHERE locale = 'en' AND MATCH(title, body) AGAINST ('+cotton* +panjabi*' IN BOOLEAN MODE)");
    expect((string) ($plan[0]->key ?? ''))->toContain('title');
    expect(count(app(CatalogSearch::class)->search('cotton panjabi', 'en')->productIds))->toBeGreaterThan(50);

    $home = microtime(true);
    $this->get('/en')->assertOk();
    fwrite(STDERR, sprintf("  home page (cold cache)   %6.0f ms\n", (microtime(true) - $home) * 1000));
})->skip(! getenv('RUN_SCALE_TESTS'), 'Heavy: run with RUN_SCALE_TESTS=1');
