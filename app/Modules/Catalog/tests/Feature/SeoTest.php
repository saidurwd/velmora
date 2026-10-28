<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\AddProductMedia;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Database\Seeders\DemoCatalogSeeder;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\SavePage;
use App\Modules\Cms\Database\Seeders\DemoCmsSeeder;
use App\Modules\Cms\Models\Page;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('media');
    Storage::fake('local');
    config(['catalog.media.disk' => 'media']);
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, DemoCatalogSeeder::class, DemoCmsSeeder::class]);
    $this->actor = Actor::system('t');
});

/** @return list<array<string, mixed>> every JSON-LD block on the page, decoded (and so proven to be valid JSON) */
function jsonLd(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_map(fn ($j) => json_decode($j, true, 512, JSON_THROW_ON_ERROR), $m[1]);
}

function seoMatches(string $html, string $pattern): array
{
    preg_match_all($pattern, $html, $m);

    return $m[1];
}

describe('product page', function () {
    it('declares one canonical address and the same product in each language', function () {
        $bn = rawurlencode((string) DB::table('product_translations')->where('locale', 'bn')->where('name', 'সুতির পাঞ্জাবি')->value('slug'));
        $html = $this->get('/en/p/cotton-panjabi?opt[x]=y')->assertOk()->getContent();

        expect(seoMatches($html, '#<link rel="canonical" href="([^"]+)"#'))->toBe([url('/en/p/cotton-panjabi')])
            ->and(seoMatches($html, '#<link rel="alternate" hreflang="en" href="([^"]+)"#'))->toBe([url('/en/p/cotton-panjabi')])
            ->and(seoMatches($html, '#<link rel="alternate" hreflang="bn" href="([^"]+)"#'))->toBe([url("/bn/p/{$bn}")])
            ->and(seoMatches($html, '#<link rel="alternate" hreflang="x-default" href="([^"]+)"#'))->toBe([url('/en/p/cotton-panjabi')]);
    });

    it('points the language switcher at the matching page, not the same slug in the other language', function () {
        $bn = rawurlencode((string) DB::table('product_translations')->where('locale', 'bn')->where('name', 'সুতির পাঞ্জাবি')->value('slug'));

        $this->get('/en/p/cotton-panjabi')->assertSee('href="'.url("/bn/p/{$bn}").'"', false)->assertDontSee('href="'.url('/bn/p/cotton-panjabi').'"', false);
    });

    it('carries Open Graph and Twitter tags in the right language', function () {
        $en = $this->get('/en/p/cotton-panjabi')->getContent();
        $bn = $this->get('/bn/p/'.rawurlencode((string) DB::table('product_translations')->where('locale', 'bn')->where('name', 'সুতির পাঞ্জাবি')->value('slug')))->getContent();

        expect($en)->toContain('<meta property="og:type" content="product">')->toContain('<meta property="og:locale" content="en_US">')->toContain('<meta property="og:locale:alternate" content="bn_BD">')
            ->toContain('<meta property="og:title" content="Cotton Panjabi">')->toContain('<meta name="twitter:card" content="summary">')
            ->and($bn)->toContain('<meta property="og:locale" content="bn_BD">')->toContain('<meta property="og:title" content="সুতির পাঞ্জাবি">');
    });

    it('describes the product to search engines with valid JSON-LD: price, currency, availability, SKU, brand and breadcrumbs', function () {
        $blocks = jsonLd($this->get('/en/p/cotton-panjabi')->getContent());
        $product = collect($blocks)->firstWhere('@type', 'Product');
        $crumbs = collect($blocks)->firstWhere('@type', 'BreadcrumbList');

        expect($product['@context'])->toBe('https://schema.org')
            ->and($product['name'])->toBe('Cotton Panjabi')->and($product['sku'])->toBe('PANJ-001')->and($product['brand'])->toBe(['@type' => 'Brand', 'name' => 'Dhaka Craft'])
            ->and($product['offers'])->toMatchArray(['@type' => 'Offer', 'price' => '1850.00', 'priceCurrency' => 'BDT', 'availability' => 'https://schema.org/InStock', 'url' => url('/en/p/cotton-panjabi')])
            ->and(array_column($crumbs['itemListElement'], 'position'))->toBe([1, 2, 3])
            ->and(end($crumbs['itemListElement'])['name'])->toBe('Cotton Panjabi');
    });

    it('reports out-of-stock and includes images when there are some', function () {
        $productId = (int) DB::table('product_variants')->where('sku', 'PANJ-001')->value('product_id');
        $im = imagecreatetruecolor(1200, 900);
        ob_start();
        imagejpeg($im);
        app(AddProductMedia::class)->handle($this->actor, Product::findOrFail($productId), (string) ob_get_clean(), 'a.jpg', ['en' => 'Front']);
        DB::table('stock_levels')->where('variant_id', DB::table('product_variants')->where('sku', 'PANJ-001')->value('id'))->update(['on_hand' => 0]);

        $html = $this->get('/en/p/cotton-panjabi')->getContent();
        $product = collect(jsonLd($html))->firstWhere('@type', 'Product');

        expect($product['offers']['availability'])->toBe('https://schema.org/OutOfStock')->and($product['image'][0])->toStartWith('http')
            ->and(seoMatches($html, '#<meta property="og:image" content="([^"]+)"#'))->toHaveCount(1)->and($html)->toContain('summary_large_image');
    });

    it('cannot be broken out of by a hostile product name', function () {
        $p = app(SaveProduct::class)->handle($this->actor, null, new ProductData(ProductType::Digital, [new TranslationInput('en', '</script><script>alert(1)</script> "x" & y')], categoryIds: [(int) DB::table('categories')->value('id')], defaultVariant: new VariantData('EVIL-1')));
        app(SetRegularPrice::class)->handle($this->actor, (int) $p->variants()->value('id'), Money::of(10000));
        app(TransitionProduct::class)->publish($this->actor, $p);

        $html = $this->get('/en/p/'.rawurlencode((string) $p->translate('slug', 'en')))->assertOk()->getContent();
        $ld = substr($html, (int) strpos($html, 'application/ld+json'));

        expect($ld)->not->toContain('</script><script>alert(1)')->and($ld)->toContain('</script>');
        expect(collect(jsonLd($html))->firstWhere('@type', 'Product')['name'])->toBe('</script><script>alert(1)</script> "x" & y');
    });
});

describe('listings and home', function () {
    it('gives every listing one indexable address and keeps filtered, sorted and search pages out of the index', function () {
        $base = url('/en/c/clothing');
        $canonical = fn (string $path) => seoMatches($this->get($path)->assertOk()->getContent(), '#<link rel="canonical" href="([^"]+)"#')[0];

        expect($canonical('/en/c/clothing'))->toBe($base)->and($canonical('/en/c/clothing?brand[]=dhaka-craft&sort=price_asc'))->toBe($base)->and($canonical('/en/c/clothing?page=2'))->toBe($base.'?page=2');
        $this->get('/en/c/clothing')->assertDontSee('name="robots"', false);
        $this->get('/en/c/clothing?brand[]=dhaka-craft')->assertSee('<meta name="robots" content="noindex, follow">', false);
        $this->get('/en/shop?sort=price_asc')->assertSee('noindex, follow', false);
        $this->get('/en/search?q=panjabi')->assertSee('noindex, follow', false);
        $this->get('/en/c/clothing')->assertDontSee('?min=', false);
    });

    it('pairs a category with its other-language address and describes the trail', function () {
        $bn = rawurlencode((string) DB::table('category_translations')->where('locale', 'bn')->where('name', 'পোশাক')->value('slug'));
        $html = $this->get('/en/c/clothing')->getContent();

        expect(seoMatches($html, '#<link rel="alternate" hreflang="bn" href="([^"]+)"#'))->toBe([url("/bn/c/{$bn}")])
            ->and(collect(jsonLd($html))->firstWhere('@type', 'BreadcrumbList')['itemListElement'][0]['name'])->toBe('Shop');
    });

    it('describes the store on the home page: Organization, WebSite with search, description', function () {
        app(Settings::class)->set('brand.tagline.en', 'Everyday, delivered');
        $html = $this->get('/en')->getContent();
        $blocks = collect(jsonLd($html));

        expect($blocks->firstWhere('@type', 'Organization')['url'])->toBe(url('/en'))
            ->and($blocks->firstWhere('@type', 'WebSite')['potentialAction']['target']['urlTemplate'])->toBe(url('/en/search').'?q={search_term_string}')
            ->and($html)->toContain('<meta name="description" content="Everyday, delivered">');
    });
});

describe('changed slugs', function () {
    it('keeps a product\'s old address working with a permanent redirect, through several renames', function () {
        $p = Product::findOrFail((int) DB::table('product_variants')->where('sku', 'PANJ-001')->value('product_id'));
        $rename = fn (string $name, string $slug) => app(SaveProduct::class)->handle($this->actor, $p->fresh(), new ProductData($p->product_type, [new TranslationInput('en', $name, $slug), new TranslationInput('bn', 'সুতির পাঞ্জাবি')]));

        $rename('Cotton Panjabi Deluxe', 'cotton-panjabi-deluxe');
        $rename('Cotton Panjabi Royal', 'cotton-panjabi-royal');

        $this->get('/en/p/cotton-panjabi')->assertStatus(301)->assertRedirect(url('/en/p/cotton-panjabi-royal'));
        $this->get('/en/p/cotton-panjabi-deluxe')->assertStatus(301)->assertRedirect(url('/en/p/cotton-panjabi-royal'));
        $this->get('/en/p/cotton-panjabi-royal')->assertOk();
        $this->get('/en/p/never-existed')->assertNotFound();
    });

    it('stops redirecting a slug once another product takes it', function () {
        $p = Product::findOrFail((int) DB::table('product_variants')->where('sku', 'PANJ-001')->value('product_id'));
        app(SaveProduct::class)->handle($this->actor, $p, new ProductData($p->product_type, [new TranslationInput('en', 'Cotton Panjabi New', 'cotton-panjabi-new')]));
        expect(DB::table('slug_redirects')->where('old_slug', 'cotton-panjabi')->exists())->toBeTrue();

        $other = Product::findOrFail((int) DB::table('product_variants')->where('sku', 'PANJ-002')->value('product_id'));
        app(SaveProduct::class)->handle($this->actor, $other, new ProductData($other->product_type, [new TranslationInput('en', 'Cotton Panjabi', 'cotton-panjabi')]));

        expect(DB::table('slug_redirects')->where('old_slug', 'cotton-panjabi')->exists())->toBeFalse();
        $this->get('/en/p/cotton-panjabi')->assertOk();
        expect(seoMatches($this->get('/en/p/cotton-panjabi')->getContent(), '#<meta property="og:title" content="([^"]+)"#'))->toBe(['Cotton Panjabi']);
    });

    it('redirects renamed categories and pages too', function () {
        $cat = Category::findOrFail((int) DB::table('category_translations')->where('slug', 'clothing')->value('category_id'));
        app(SaveCategory::class)->handle($this->actor, $cat, null, [new TranslationInput('en', 'Apparel', 'apparel'), new TranslationInput('bn', 'পোশাক')]);
        $this->get('/en/c/clothing')->assertStatus(301)->assertRedirect(url('/en/c/apparel'));

        $page = Page::findOrFail((int) DB::table('cms_page_translations')->where('slug', 'faq')->value('page_id'));
        app(SavePage::class)->handle($this->actor, $page, ['en' => ['title' => 'Questions', 'slug' => 'questions', 'body' => '<p>x</p>']]);
        $this->get('/en/pages/faq')->assertStatus(301)->assertRedirect(url('/en/pages/questions'));
        $this->get('/en/pages/questions')->assertOk()->assertSee('<link rel="canonical" href="'.url('/en/pages/questions').'"', false);
    });
});

describe('sitemaps and robots', function () {
    it('publishes an index and one set of files per language, with alternates, x-default and last-modified dates', function () {
        $index = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $sitemaps = seoMatches($index, '#<loc>([^<]+)</loc>#');

        expect($sitemaps)->toHaveCount(2)->and(simplexml_load_string($index))->not->toBeFalse();
        $en = $this->get(parse_url(collect($sitemaps)->first(fn ($u) => str_contains($u, 'sitemap-en-')), PHP_URL_PATH))->assertOk()->getContent();
        $bn = $this->get(parse_url(collect($sitemaps)->first(fn ($u) => str_contains($u, 'sitemap-bn-')), PHP_URL_PATH))->assertOk()->getContent();

        expect(simplexml_load_string($en))->not->toBeFalse()->and(simplexml_load_string($bn))->not->toBeFalse();
        $bnSlug = rawurlencode((string) DB::table('product_translations')->where('locale', 'bn')->where('name', 'সুতির পাঞ্জাবি')->value('slug'));
        expect($en)->toContain('<loc>'.url('/en/p/cotton-panjabi').'</loc>')->toContain('hreflang="bn" href="'.url("/bn/p/{$bnSlug}").'"')->toContain('hreflang="x-default"')->toContain('<lastmod>')
            ->toContain('<loc>'.url('/en/shop').'</loc>')->toContain('<loc>'.url('/en').'</loc>')->toContain('<loc>'.url('/en/pages/faq').'</loc>')->toContain('<loc>'.url('/en/c/clothing').'</loc>')
            ->and($bn)->toContain('<loc>'.url("/bn/p/{$bnSlug}").'</loc>')
            ->and(implode('', seoMatches($bn, '#<loc>([^<]+)</loc>#')))->not->toContain('/en/'); // only Bangla addresses are listed as pages; English ones appear only as alternates
    });

    it('lists only what the public can see', function () {
        app(TransitionProduct::class)->unpublish($this->actor, Product::findOrFail((int) DB::table('product_variants')->where('sku', 'PANJ-001')->value('product_id')));
        DB::table('categories')->where('id', DB::table('category_translations')->where('slug', 'footwear')->value('category_id'))->update(['is_active' => false]);
        Page::query()->whereKey(DB::table('cms_page_translations')->where('slug', 'faq')->value('page_id'))->update(['status' => 'draft']);

        $this->artisan('seo:sitemaps')->assertSuccessful();
        $all = collect(seoMatches($this->get('/sitemap.xml')->getContent(), '#<loc>([^<]+)</loc>#'))->map(fn ($u) => $this->get(parse_url($u, PHP_URL_PATH))->getContent())->implode('');

        expect($all)->not->toContain('/en/p/cotton-panjabi<')->not->toContain('/en/c/footwear')->not->toContain('/en/pages/faq')->toContain('/en/p/embroidered-panjabi')->toContain('/en/pages/about');
    });

    it('replaces old files on regeneration and refuses anything but generated file names', function () {
        $this->get('/sitemap.xml')->assertOk();
        $before = Storage::disk('local')->files('sitemaps');
        sleep(1);
        $this->artisan('seo:sitemaps')->assertSuccessful();
        $after = Storage::disk('local')->files('sitemaps');

        expect(count($after))->toBe(count($before))->and(array_values(array_intersect($before, $after)))->toBe(['sitemaps/sitemap.xml']);
        $this->get('/sitemaps/..%2F..%2F.env')->assertNotFound();
        $this->get('/sitemaps/passwd')->assertNotFound();
        $this->get('/sitemaps/sitemap-en-1-20200101000000.xml')->assertNotFound();
    });

    it('blocks all crawling outside production and only the private areas in production, pointing at the sitemap', function () {
        $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertSee('Disallow: /')->assertDontSee('Sitemap:');

        $this->app['env'] = 'production';
        $this->get('/robots.txt')->assertSee('Disallow: /admin')->assertSee('Disallow: /*/search')->assertSee('Sitemap: '.url('/sitemap.xml'))->assertSee('Allow: /');
    });
});
