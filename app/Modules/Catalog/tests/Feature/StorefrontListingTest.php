<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Database\Seeders\DemoCatalogSeeder;
use App\Modules\Catalog\DTOs\ListingFilters;
use App\Modules\Catalog\Jobs\ReconcileListing;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\Storefront\ListingProjection;
use App\Modules\Cms\Database\Seeders\DemoCmsSeeder;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Actions\ScheduleSale;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Contracts\PriceCatalog;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('media');
    config(['catalog.media.disk' => 'media']);
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, DemoCatalogSeeder::class, DemoCmsSeeder::class]);
});

/** @return list<int> product ids in page order, read from the rendered page's data-free card links */
function listed(string $url): array
{
    $html = test()->get($url)->assertOk()->getContent();
    preg_match_all('#/(?:en|bn)/p/([^"\']+)#u', (string) $html, $m);

    return array_values(array_unique(array_map('urldecode', $m[1])));
}

it('renders the home page in both languages with banners, categories and product rails', function () {
    $this->get('/en')->assertOk()->assertSee('Everyday essentials, delivered')->assertSee('Shop by category')->assertSee('Clothing')->assertSee('New arrivals')->assertSee('fetchpriority="high"', false);
    $this->get('/bn')->assertOk()->assertSee('প্রতিদিনের প্রয়োজন, দোরগোড়ায়')->assertSee('ক্যাটাগরি অনুযায়ী কিনুন')->assertSee('পোশাক')->assertSee('নতুন পণ্য');
});

it('lists every live product exactly once across pages, newest first', function () {
    $total = DB::table('catalog_listing')->count();
    expect($total)->toBe(Product::count());

    $page1 = listed('/en/shop');
    $page2 = listed('/en/shop?page=2');

    expect($page1)->toHaveCount(24)->and($page2)->toHaveCount($total - 24)->and(array_intersect($page1, $page2))->toBe([]);
    $this->get('/en/shop')->assertSee('rel="next"', false)->assertSee("{$total} products");
});

it('serves category, brand and collection pages in the visitor\'s language', function () {
    $this->get('/en/c/clothing')->assertOk()->assertSee('Clothing')->assertSee('Cotton Panjabi');
    $this->get('/bn/c/'.rawurlencode(DB::table('category_translations')->where('locale', 'bn')->where('name', 'পোশাক')->value('slug')))->assertOk()->assertSee('সুতির পাঞ্জাবি');
    $this->get('/en/brands/dhaka-craft')->assertOk()->assertSee('Dhaka Craft');
    $this->get('/en/collections/eid-specials')->assertOk()->assertSee('Cotton Panjabi')->assertDontSee('Steel Water Bottle');

    $this->get('/bn/c/clothing')->assertNotFound();   // slugs belong to their language
    $this->get('/en/c/nope')->assertNotFound();
});

it('does not show inactive categories', function () {
    DB::table('categories')->update(['is_active' => false]);

    $this->get('/en/c/clothing')->assertNotFound();
});

it('filters by brand, price range, type, availability and attribute', function () {
    $dc = DB::table('catalog_listing')->where('brand_id', DB::table('brand_translations')->where('slug', 'dhaka-craft')->value('brand_id'))->count();
    expect(listed('/en/shop?brand[]=dhaka-craft'))->toHaveCount(min(24, $dc));

    $priced = DB::table('catalog_listing')->whereBetween('min_price_minor', [100000, 200000])->count();
    expect(count(listed('/en/shop?min=1000&max=2000&sort=price_asc')))->toBe(min(24, $priced));
    expect(listed('/en/shop?type[]=digital'))->toHaveCount(1);
    expect(listed('/en/shop?type[]=service'))->toHaveCount(1);

    $black = DB::table('catalog_listing')->whereIn('product_id', DB::table('product_variants')->join('variant_option_values', 'variant_option_values.variant_id', '=', 'product_variants.id')->join('attribute_values', 'attribute_values.id', '=', 'variant_option_values.attribute_value_id')->where('attribute_values.code', 'black')->select('product_variants.product_id'))->count();
    expect($black)->toBeGreaterThan(0)->and(listed('/en/shop?attr[color][]=black'))->toHaveCount($black);

    expect(listed('/en/shop?attr[color][]=black&attr[size][]=xl'))->not->toBeEmpty()->toHaveCount(DB::table('catalog_listing')->whereIn('product_id', DB::table('products')->whereIn('id', DB::table('product_variants')->whereIn('id', DB::table('variant_option_values')->join('attribute_values', 'attribute_values.id', '=', 'variant_option_values.attribute_value_id')->where('attribute_values.code', 'xl')->select('variant_id'))->select('product_id'))->select('id'))->whereIn('product_id', DB::table('product_variants')->whereIn('id', DB::table('variant_option_values')->join('attribute_values', 'attribute_values.id', '=', 'variant_option_values.attribute_value_id')->where('attribute_values.code', 'black')->select('variant_id'))->select('product_id'))->count());
});

it('sorts by price in both directions with stable paging', function () {
    $asc = listed('/en/shop?sort=price_asc');
    $desc = listed('/en/shop?sort=price_desc');
    $prices = fn (array $slugs) => array_map(fn ($s) => (int) DB::table('catalog_listing')->whereIn('product_id', DB::table('product_translations')->where('slug', $s)->select('product_id'))->value('min_price_minor'), $slugs);

    expect($prices($asc))->toBe(collect($prices($asc))->sort()->values()->all())
        ->and($prices($desc))->toBe(collect($prices($desc))->sortDesc()->values()->all())
        ->and(array_intersect($asc, listed('/en/shop?sort=price_asc&page=2')))->toBe([]);
});

it('ignores malformed filters instead of failing', function () {
    $this->get('/en/shop?min=abc&max=-5&sort=drop-table&page=-3&brand=notanarray&type[]=robot&attr[x]=1&in_stock[]=2')->assertOk();
    $this->get('/en/shop?page=9999')->assertOk()->assertSee(__('catalog::storefront.none_title', [], 'en'));
});

it('reads Bangla digits and swaps an inverted price range', function () {
    $f = ListingFilters::fromQuery(['min' => '২০০০', 'max' => '১০০০']);

    expect([$f->minMinor, $f->maxMinor])->toBe([100000, 200000]);
});

it('shows filters as a plain GET form so browsing works without JavaScript', function () {
    $this->get('/en/shop')->assertSee('method="GET"', false)->assertSee('name="brand[]"', false)->assertSee('<noscript>', false);
});

it('keeps drafts and archived products off the storefront and removes them when unpublished', function () {
    $product = Product::query()->whereHas('translations', fn ($q) => $q->where('slug', 'cotton-panjabi'))->firstOrFail();
    expect(listed('/en/shop?sort=price_asc&min=1850&max=1850'))->toContain('cotton-panjabi');

    app(TransitionProduct::class)->unpublish(Actor::system('t'), $product);

    expect(DB::table('catalog_listing')->where('product_id', $product->id)->exists())->toBeFalse()
        ->and(listed('/en/shop?min=1850&max=1850'))->not->toContain('cotton-panjabi');
});

it('shows a changed price, a sale and a stock-out within one reconcile run', function () {
    $actor = Actor::system('t');
    $variantId = (int) DB::table('product_variants')->where('sku', 'PANJ-001')->value('id');
    $productId = (int) DB::table('product_variants')->where('id', $variantId)->value('product_id');
    $listing = fn () => DB::table('catalog_listing')->where('product_id', $productId)->first();

    app(ReconcileListing::class)->handle(app(ListingProjection::class), app(PriceCatalog::class), app(Availability::class), app(Settings::class), app(Clock::class)); // sets the watermark

    app(SetRegularPrice::class)->handle($actor, $variantId, Money::of(150000));
    expect((int) $listing()->min_price_minor)->toBe(185000); // not yet: read model catches up on the next run

    $run = fn () => app(ReconcileListing::class)->handle(app(ListingProjection::class), app(PriceCatalog::class), app(Availability::class), app(Settings::class), app(Clock::class));
    $run();
    expect((int) $listing()->min_price_minor)->toBe(150000);

    app(ScheduleSale::class)->handle($actor, $variantId, Money::of(120000), now('Asia/Dhaka')->subHour()->format('Y-m-d H:i'), now('Asia/Dhaka')->addDay()->format('Y-m-d H:i'));
    $run();
    expect((int) $listing()->min_price_minor)->toBe(120000)->and((bool) $listing()->on_sale)->toBeTrue()->and((int) $listing()->compare_at_minor)->toBe(150000);

    app(AdjustStock::class)->handle($actor, $variantId, 'main', -(int) DB::table('stock_levels')->where('variant_id', $variantId)->value('on_hand'), 'write_off', 'test');
    $run();
    expect((bool) $listing()->in_stock)->toBeFalse();
    expect(listed('/en/shop?in_stock=1&min=1200&max=1200'))->not->toContain('cotton-panjabi');
});

it('rebuilds the whole read model on demand', function () {
    DB::table('catalog_listing')->delete();

    $this->artisan('catalog:reindex')->assertSuccessful();

    expect(DB::table('catalog_listing')->count())->toBe(Product::count());
});

it('answers a listing page in a bounded number of queries', function () {
    DB::enableQueryLog();
    $this->get('/en/shop?brand[]=dhaka-craft&sort=price_asc')->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThan(30);
});

it('uses the index for sorting by newest and by price', function (string $order) {
    DB::table('catalog_listing')->count();
    $plan = DB::select("EXPLAIN SELECT product_id FROM catalog_listing ORDER BY {$order} LIMIT 24");

    expect($plan[0]->key)->not->toBeNull();
})->with(['published_at DESC, product_id DESC', 'min_price_minor ASC, product_id ASC']);
