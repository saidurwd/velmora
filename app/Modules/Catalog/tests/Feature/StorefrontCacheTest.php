<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\SaveTaxonomyEntity;
use App\Modules\Catalog\Database\Seeders\DemoCatalogSeeder;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\Jobs\ReconcileListing;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\SaveBanner;
use App\Modules\Cms\Actions\SavePage;
use App\Modules\Cms\Actions\TransitionPage;
use App\Modules\Cms\Database\Seeders\DemoCmsSeeder;
use App\Modules\Cms\Models\Page;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('media');
    config(['catalog.media.disk' => 'media']);
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, DemoCatalogSeeder::class, DemoCmsSeeder::class]);
    $this->actor = Actor::system('t');
    $this->reconcile = fn () => app()->call([app(ReconcileListing::class), 'handle']);
});

/** Counts the catalog_listing queries a request makes: zero means it was served from the cache. */
function listingQueries(Closure $request): int
{
    $count = 0;
    DB::listen(function ($q) use (&$count) {
        if (str_contains($q->sql, 'catalog_listing')) {
            $count++;
        }
    });
    $request();

    return $count;
}

it('serves a repeated listing request from the cache, and only the cache', function () {
    $first = listingQueries(fn () => $this->get('/en/shop')->assertOk());
    $second = listingQueries(fn () => $this->get('/en/shop')->assertOk());

    expect($first)->toBeGreaterThan(0)->and($second)->toBe(0);
});

it('keeps English and Bangla pages apart in the cache', function () {
    $this->get('/en/shop?min=1850&max=1850')->assertSee('Cotton Panjabi');
    $this->get('/bn/shop?min=1850&max=1850')->assertSee('সুতির পাঞ্জাবি')->assertDontSee('Cotton Panjabi');
    $this->get('/en')->assertSee('Shop by category');
    $this->get('/bn')->assertSee('ক্যাটাগরি অনুযায়ী কিনুন');
});

it('shows a catalog edit on the very next request, not after the cache expires', function () {
    $this->get('/en/shop?min=1850&max=1850')->assertSee('Cotton Panjabi')->assertDontSee('Cotton Panjabi Deluxe');
    $this->get('/en')->assertOk(); // warm the home page too

    $p = Product::findOrFail((int) DB::table('product_variants')->where('sku', 'PANJ-001')->value('product_id'));
    app(SaveProduct::class)->handle($this->actor, $p, new ProductData($p->product_type, [new TranslationInput('en', 'Cotton Panjabi Deluxe', 'cotton-panjabi-deluxe'), new TranslationInput('bn', 'সুতির পাঞ্জাবি')]));

    $this->get('/en/shop?min=1850&max=1850')->assertSee('Cotton Panjabi Deluxe');
    $this->get('/en')->assertSee('Cotton Panjabi Deluxe');
});

it('shows a price change and a stock-out within one reconcile run (the documented ~1 minute window)', function () {
    $variant = (int) DB::table('product_variants')->where('sku', 'PANJ-001')->value('id');
    ($this->reconcile)(); // watermark
    $this->get('/en/shop?min=1850&max=1850')->assertSee('Cotton Panjabi'); // cached

    app(SetRegularPrice::class)->handle($this->actor, $variant, Money::of(177700));
    $this->get('/en/shop?min=1850&max=1850')->assertSee('Cotton Panjabi'); // still the old page until the job runs…
    ($this->reconcile)();
    $this->get('/en/shop?min=1850&max=1850')->assertDontSee('Cotton Panjabi');   // …then immediately the new price
    $this->get('/en/shop?min=1777&max=1777')->assertSee('Cotton Panjabi');

    app(AdjustStock::class)->handle($this->actor, $variant, 'main', -(int) DB::table('stock_levels')->where('variant_id', $variant)->value('on_hand'), 'write_off', 't');
    ($this->reconcile)();
    $this->get('/en/shop?min=1777&max=1777&in_stock=1')->assertDontSee('Cotton Panjabi');
});

it('shows new banners, page links and facet labels immediately', function () {
    $this->get('/en')->assertOk()->assertDontSee('Flash sale today');
    $this->get('/en/shop')->assertOk()->assertDontSee('Handloom Co');

    $media = fn () => (function () {
        $im = imagecreatetruecolor(1800, 600);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    })();
    app(SaveBanner::class)->handle($this->actor, null, ['placement' => 'home_hero', 'link_url' => '/en', 'sort_order' => 0, 'is_active' => true, 'starts_local' => null, 'ends_local' => null], ['en' => ['title' => 'Flash sale today', 'alt' => 'Flash', 'image' => $media()]]);
    $this->get('/en')->assertSee('Flash sale today');

    $page = app(SavePage::class)->handle($this->actor, null, ['en' => ['title' => 'Returns policy', 'body' => '<p>x</p>']]);
    $this->get('/en')->assertDontSee('Returns policy');
    app(TransitionPage::class)->publish($this->actor, Page::findOrFail($page->id));
    $this->get('/en')->assertSee('Returns policy');

    $brand = Brand::findOrFail((int) DB::table('brand_translations')->where('slug', 'dhaka-craft')->value('brand_id'));
    app(SaveTaxonomyEntity::class)->handle($this->actor, Brand::class, $brand, [new TranslationInput('en', 'Handloom Co', 'dhaka-craft'), new TranslationInput('bn', 'ঢাকা ক্র্যাফট')]);
    $this->get('/en/shop')->assertSee('Handloom Co');
});

it('never lets the version counter lose a bump', function () {
    $cache = app(StorefrontCache::class);
    $before = $cache->version();

    foreach (range(1, 25) as $_) {
        $cache->bump();
    }

    expect($cache->version())->toBe($before + 25);
});

it('answers an unchanged page with 304 and no body, and a changed page with a fresh 200', function () {
    $first = $this->get('/en/shop')->assertOk()->assertHeader('ETag');
    $etag = $first->headers->get('ETag');
    expect($first->headers->get('Cache-Control'))->toContain('must-revalidate')->toContain('max-age=0');

    $this->get('/en/shop', ['If-None-Match' => $etag])->assertStatus(304)->assertNoContent(304);

    $p = Product::findOrFail((int) DB::table('product_variants')->where('sku', 'PANJ-001')->value('product_id'));
    app(SaveProduct::class)->handle($this->actor, $p, new ProductData($p->product_type, [new TranslationInput('en', 'Cotton Panjabi Royal', 'cotton-panjabi-royal'), new TranslationInput('bn', 'সুতির পাঞ্জাবি')]));
    $second = $this->get('/en/shop', ['If-None-Match' => $etag])->assertOk();
    expect($second->headers->get('ETag'))->not->toBe($etag);
});

it('preloads only the fonts the page language needs', function () {
    if (! file_exists(public_path('build/manifest.json')) && ! file_exists(public_path('build/.vite/manifest.json'))) {
        $this->markTestSkipped('Assets are not built in this environment.');
    }
    $this->withVite();

    $en = $this->get('/en')->getContent();
    $bn = $this->get('/bn')->getContent();

    expect($en)->toContain('rel="preload"')->toContain('inter-latin-wght')->not->toContain('noto-sans-bengali-bengali-400')
        ->and($bn)->toContain('inter-latin-wght')->toContain('noto-sans-bengali-bengali-400-normal');
});
