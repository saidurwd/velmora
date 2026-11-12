<?php

declare(strict_types=1);

use App\Modules\Catalog\Database\Seeders\DemoCatalogSeeder;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Database\Seeders\DemoCmsSeeder;
use App\Modules\Cms\Models\Page;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/* Phase 11, slice 3: the catalog for the app. Read only. Every answer is checked against the website's own listing and against the committed contract. */

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    Storage::fake('media');
    config(['catalog.media.disk' => 'media']);
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, GeographySeeder::class, DemoCatalogSeeder::class, DemoCmsSeeder::class]);
    $this->slugs = fn (array $cards) => array_column($cards, 'slug');
    $this->slugOf = fn (string $sku): string => (string) DB::table('product_translations')->where('locale', 'en')->where('product_id', DB::table('product_variants')->where('sku', 'like', $sku.'%')->value('product_id'))->value('slug');
});

/** @return list<string> product slugs in page order, read from the website's listing page */
function webListed(string $url): array
{
    preg_match_all('#/en/p/([^"\']+)#u', (string) test()->get($url)->assertOk()->getContent(), $m);

    return array_values(array_unique(array_map('urldecode', $m[1])));
}

describe('GET /products', function () {
    it('lists the shop a page at a time, with the page envelope, and matches the contract', function () {
        $total = Product::count();
        $res = $this->getJson('/api/v1/products');

        $res->assertOk()->assertJsonPath('meta.page', 1)->assertJsonPath('meta.per_page', 24)->assertJsonPath('meta.total', $total)->assertJsonPath('meta.last_page', (int) ceil($total / 24))->assertJsonPath('meta.sort', 'newest');
        expect($res->json('data'))->toHaveCount(24)->and($res->json('links.prev'))->toBeNull()->and($res->json('links.next'))->toContain('page=2');
        assertMatchesOpenApi($res, 'GET', '/api/v1/products');

        $two = $this->getJson('/api/v1/products?page=2')->assertOk();
        expect(array_intersect(($this->slugs)($res->json('data')), ($this->slugs)($two->json('data'))))->toBe([])->and($two->json('links.prev'))->toContain('page=1');
        assertMatchesOpenApi($two, 'GET', '/api/v1/products');
    });

    it('gives the same products in the same order as the website for the same question', function () {
        foreach ([['/en/shop', '/api/v1/products'], ['/en/shop?sort=price_asc', '/api/v1/products?sort=price_asc'], ['/en/shop?sort=price_desc', '/api/v1/products?sort=price_desc'], ['/en/c/clothing', '/api/v1/products?filter[category]=clothing'], ['/en/collections/eid-specials', '/api/v1/products?filter[collection]=eid-specials'], ['/en/shop?brand[]=dhaka-craft', '/api/v1/products?filter[brand]=dhaka-craft']] as [$web, $api]) {
            expect(($this->slugs)($this->getJson($api)->assertOk()->json('data')))->toBe(webListed($web), $api);
        }
    });

    it('narrows by price (in minor units), type, availability and attribute, and says what is left in the facets', function () {
        $priced = $this->getJson('/api/v1/products?filter[price_min]=100000&filter[price_max]=200000&sort=price_asc&per_page=60')->assertOk();
        expect($priced->json('data'))->not->toBeEmpty();
        foreach ($priced->json('data') as $c) {
            expect($c['price']['amount_minor'])->toBeGreaterThanOrEqual(100000)->toBeLessThanOrEqual(200000)->and($c['price']['currency'])->toBe('BDT');
        }

        expect($this->getJson('/api/v1/products?filter[type]=digital')->json('meta.total'))->toBe(1)
            ->and($this->getJson('/api/v1/products?filter[type]=service,digital')->json('meta.total'))->toBe(2);

        $black = $this->getJson('/api/v1/products?filter[attr][color]=black&per_page=60');
        expect($black->json('meta.total'))->toBeGreaterThan(0)->and($black->json('meta.total'))->toBe(count(webListed('/en/shop?attr[color][]=black')));

        $facets = $this->getJson('/api/v1/products')->json('meta.facets');
        expect($facets['brands'])->not->toBeEmpty()->and($facets['price']['min']['amount_minor'])->toBeLessThanOrEqual($facets['price']['max']['amount_minor'])->and(array_keys($facets['types']))->toContain('physical');
    });

    it('prices a card as the product page does', function () {
        $card = $this->getJson('/api/v1/products?per_page=5')->json('data.0');
        $page = $this->getJson('/api/v1/products/'.$card['slug'])->assertOk()->json('data');

        expect($card['price'])->toBe($page['price'])->and($card['id'])->toBe($page['id']);
    });

    it('refuses what it cannot read, with the field named, instead of guessing', function () {
        foreach ([
            '?sort=relevance' => 'sort', '?sort=drop-table' => 'sort', '?per_page=100' => 'per_page', '?per_page=0' => 'per_page', '?page=-3' => 'page', '?page=abc' => 'page',
            '?filter[price_min]=abc' => 'filter.price_min', '?filter[price_min]=500&filter[price_max]=100' => 'filter.price_max', '?filter[category]=no-such' => 'filter.category',
            '?filter[category]=clothing&filter[collection]=eid-specials' => 'filter.collection', '?filter[category]=bad/slug' => 'filter.category', '?filter[in_stock]=maybe' => 'filter.in_stock',
        ] as $query => $field) {
            $this->getJson('/api/v1/products'.$query)->assertStatus(422)->assertJsonPath('code', 'validation.failed')->assertJsonValidationErrors([$field]);
        }
    });

    it('answers in Bangla with Bangla names and falls back to English where a translation is missing', function () {
        $en = $this->getJson('/api/v1/products?per_page=60')->json('data');
        $bn = $this->getJson('/api/v1/products?per_page=60', ['Accept-Language' => 'bn']);

        $bn->assertOk()->assertHeader('Content-Language', 'bn');
        expect(array_column($bn->json('data'), 'id'))->toBe(array_column($en, 'id'))->and(array_column($bn->json('data'), 'name'))->not->toBe(array_column($en, 'name'));
    });

    it('does a fixed number of queries however many products a page holds (no N+1)', function () {
        $count = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            test()->getJson($url)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $count('/api/v1/products?per_page=3'); // warm the caches
        $few = $count('/api/v1/products?per_page=5&page=2');
        $many = $count('/api/v1/products?per_page=40&page=1');

        expect($many)->toBeLessThanOrEqual($few + 2);
    });
});

describe('GET /products/{slug}', function () {
    it('shows one product with its options, the selected variant and its price, and matches the contract', function () {
        $slug = ($this->slugOf)('TEE');
        $res = $this->getJson("/api/v1/products/{$slug}");

        $res->assertOk()->assertJsonPath('data.slug', $slug)->assertJsonPath('data.type', 'physical');
        assertMatchesOpenApi($res, 'GET', "/api/v1/products/{$slug}");
        $data = $res->json('data');
        expect($data['options'])->not->toBeEmpty()->and(array_column($data['options'], 'code'))->toContain('size', 'color')
            ->and($data['variant']['id'])->toBeString()->and($data['variant']['sku'])->toStartWith('TEE')->and($data['price']['amount_minor'])->toBe(65000)->and($data['variant_count'])->toBeGreaterThan(1);
    });

    it('selects the variant asked for, and says which other choices would be in stock', function () {
        $slug = ($this->slugOf)('TEE');
        $default = $this->getJson("/api/v1/products/{$slug}")->json('data');
        $xl = $this->getJson("/api/v1/products/{$slug}?options[size]=xl&options[color]=navy");

        $xl->assertOk();
        $selected = collect($xl->json('data.options'))->mapWithKeys(fn ($o) => [$o['code'] => collect($o['values'])->firstWhere('selected', true)['code'] ?? null])->all();
        expect($selected)->toBe(['size' => 'xl', 'color' => 'navy'])->and($xl->json('data.variant.id'))->not->toBe($default['variant']['id']);
        assertMatchesOpenApi($xl, 'GET', "/api/v1/products/{$slug}");
        $this->getJson("/api/v1/products/{$slug}?options[size]=nonsense")->assertOk(); // an unknown choice falls back to the best match
    });

    it('finds a product by its Bangla or an old slug, and says its current slug', function () {
        $en = ($this->slugOf)('TEE');
        $bnSlug = (string) DB::table('product_translations')->where('locale', 'bn')->where('product_id', DB::table('product_translations')->where('slug', $en)->value('product_id'))->value('slug');

        $this->getJson('/api/v1/products/'.rawurlencode($bnSlug))->assertOk()->assertJsonPath('data.slug', $en);
        $this->getJson('/api/v1/products/'.rawurlencode($bnSlug), ['Accept-Language' => 'bn'])->assertOk()->assertJsonPath('data.slug', $bnSlug);
    });

    it('does not show a draft, an archived or an unknown product', function () {
        $slug = ($this->slugOf)('TEE');
        $this->getJson('/api/v1/products/no-such-product')->assertNotFound()->assertJsonPath('code', 'product.not_found');

        DB::table('products')->where('id', DB::table('product_translations')->where('slug', $slug)->value('product_id'))->update(['status' => 'draft']);
        $this->getJson("/api/v1/products/{$slug}")->assertNotFound()->assertJsonPath('code', 'product.not_found');
    });

    it('lists related products, never the product itself, at most the limit asked', function () {
        $slug = ($this->slugOf)('TEE');
        $res = $this->getJson("/api/v1/products/{$slug}/related?limit=4");

        $res->assertOk();
        expect($res->json('data'))->not->toBeEmpty()->toHaveCount(4)->and(array_column($res->json('data'), 'slug'))->not->toContain($slug);
        assertMatchesOpenApi($res, 'GET', "/api/v1/products/{$slug}/related");
        $this->getJson('/api/v1/products/no-such/related')->assertNotFound();
    });
});

describe('categories, brands and collections', function () {
    it('gives the category tree, hiding inactive categories and everything under them', function () {
        $res = $this->getJson('/api/v1/categories');
        $res->assertOk();
        assertMatchesOpenApi($res, 'GET', '/api/v1/categories');

        $clothing = collect($res->json('data'))->firstWhere('slug', 'clothing');
        expect($clothing)->not->toBeNull()->and($clothing['name'])->toBe('Clothing');

        $id = DB::table('category_translations')->where('slug', 'clothing')->value('category_id');
        DB::table('categories')->where('id', $id)->update(['is_active' => false]);
        $slugs = function (array $nodes) use (&$slugs): array {
            return collect($nodes)->flatMap(fn ($n) => [$n['slug'], ...$slugs($n['children'])])->all();
        };
        expect($slugs($this->getJson('/api/v1/categories')->json('data')))->not->toContain('clothing');
    });

    it('shows one category with its trail and children', function () {
        $res = $this->getJson('/api/v1/categories/clothing');

        $res->assertOk()->assertJsonPath('data.slug', 'clothing')->assertJsonStructure(['data' => ['id', 'name', 'description', 'ancestors', 'children']]);
        assertMatchesOpenApi($res, 'GET', '/api/v1/categories/clothing');
        $this->getJson('/api/v1/categories/no-such')->assertNotFound()->assertJsonPath('code', 'resource.not_found');
    });

    it('hides what staff switched off: a category, brand or collection that is inactive is not found, listed or usable as a filter', function () {
        DB::table('categories')->update(['is_active' => false]);
        DB::table('brands')->update(['is_active' => false]);
        DB::table('collections')->update(['is_active' => false]);

        $this->getJson('/api/v1/categories/clothing')->assertNotFound();
        $this->getJson('/api/v1/collections/eid-specials')->assertNotFound();
        expect($this->getJson('/api/v1/brands')->assertOk()->json('data'))->toBe([]);
        $this->getJson('/api/v1/products?filter[category]=clothing')->assertStatus(422)->assertJsonValidationErrors(['filter.category']);
        $this->getJson('/api/v1/products?filter[collection]=eid-specials')->assertStatus(422)->assertJsonValidationErrors(['filter.collection']);
    });

    it('lists brands by name and shows a collection', function () {
        $brands = $this->getJson('/api/v1/brands');
        $brands->assertOk();
        assertMatchesOpenApi($brands, 'GET', '/api/v1/brands');
        expect(array_column($brands->json('data'), 'slug'))->toContain('dhaka-craft');

        $c = $this->getJson('/api/v1/collections/eid-specials');
        $c->assertOk()->assertJsonPath('data.slug', 'eid-specials');
        assertMatchesOpenApi($c, 'GET', '/api/v1/collections/eid-specials');
        $this->getJson('/api/v1/collections/no-such')->assertNotFound();
    });
});

describe('search', function () {
    it('finds products, best first, in English and Bangla, and matches the contract', function () {
        $res = $this->getJson('/api/v1/search?q=panjabi');

        $res->assertOk()->assertJsonPath('meta.query', 'panjabi')->assertJsonPath('meta.sort', 'relevance');
        expect($res->json('data.0.name'))->toContain('Panjabi')->and($res->json('meta.total'))->toBeGreaterThan(0);
        assertMatchesOpenApi($res, 'GET', '/api/v1/search?q=panjabi');

        $bn = $this->getJson('/api/v1/search?q='.rawurlencode('পাঞ্জাবি'), ['Accept-Language' => 'bn']);
        expect($bn->assertOk()->json('meta.total'))->toBeGreaterThan(0);
    });

    it('suggests a corrected spelling when the words as typed find nothing', function () {
        $res = $this->getJson('/api/v1/search?q=panjabbi');

        $res->assertOk();
        expect($res->json('meta.corrected_query'))->not->toBeNull()->and($res->json('meta.total'))->toBeGreaterThan(0);
    });

    it('takes the same filters and sort as the list, and refuses a missing or oversize query', function () {
        $this->getJson('/api/v1/search?q=shirt&sort=price_asc&filter[in_stock]=1')->assertOk()->assertJsonPath('meta.sort', 'price_asc');
        $this->getJson('/api/v1/search')->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->getJson('/api/v1/search?q='.str_repeat('a', 101))->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->getJson('/api/v1/search?q=nothingmatchesthis')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
    });

    it('logs the first page of a search (text and count only) and nothing for later pages or suggestions', function () {
        $this->getJson('/api/v1/search?q=panjabi')->assertOk();
        $logged = DB::table('search_queries')->count();
        $this->getJson('/api/v1/search?q=panjabi&page=2')->assertOk();
        $this->getJson('/api/v1/search/autocomplete?q=panj')->assertOk();

        expect($logged)->toBe(1)->and(DB::table('search_queries')->count())->toBe(1);
    });

    it('suggests product names from two characters, at most the limit', function () {
        $res = $this->getJson('/api/v1/search/autocomplete?q=pa&limit=3');

        $res->assertOk();
        expect(count($res->json('data')))->toBeLessThanOrEqual(3);
        assertMatchesOpenApi($res, 'GET', '/api/v1/search/autocomplete?q=pa');
        expect($this->getJson('/api/v1/search/autocomplete?q=p')->assertOk()->json('data'))->toBe([]);
        $this->getJson('/api/v1/search/autocomplete?q=pa&limit=50')->assertStatus(422);
    });
});

describe('geography and pages', function () {
    it('gives divisions, then the districts and upazilas under one, in the asked language', function () {
        $div = $this->getJson('/api/v1/geo/divisions');
        $div->assertOk();
        assertMatchesOpenApi($div, 'GET', '/api/v1/geo/divisions');
        expect($div->json('data'))->toHaveCount(8);

        $dhaka = collect($div->json('data'))->firstWhere('name', 'Dhaka');
        $districts = $this->getJson('/api/v1/geo/districts?division='.$dhaka['id']);
        $districts->assertOk();
        assertMatchesOpenApi($districts, 'GET', '/api/v1/geo/districts?division=1');
        expect($districts->json('data'))->not->toBeEmpty();

        $up = $this->getJson('/api/v1/geo/upazilas?district='.$districts->json('data.0.id'));
        $up->assertOk();
        assertMatchesOpenApi($up, 'GET', '/api/v1/geo/upazilas?district=1');

        expect(collect($this->getJson('/api/v1/geo/divisions', ['Accept-Language' => 'bn'])->json('data'))->pluck('name')->all())->toContain('ঢাকা');
    });

    it('refuses an unknown or missing id', function () {
        $this->getJson('/api/v1/geo/districts?division=99999')->assertNotFound();
        $this->getJson('/api/v1/geo/upazilas?district=99999')->assertNotFound();
        $this->getJson('/api/v1/geo/districts')->assertStatus(422)->assertJsonValidationErrors(['division']);
    });

    it('shows a published page and nothing else', function () {
        $res = $this->getJson('/api/v1/pages/about');

        $res->assertOk()->assertJsonPath('data.slug', 'about')->assertJsonPath('data.kind', null)->assertJsonPath('data.locale', 'en');
        expect($res->json('data.body_html'))->toContain('<p>');
        assertMatchesOpenApi($res, 'GET', '/api/v1/pages/about');

        $this->getJson('/api/v1/pages/no-such-page')->assertNotFound()->assertJsonPath('code', 'page.not_found');
        Page::query()->update(['status' => 'draft']);
        $this->getJson('/api/v1/pages/about')->assertNotFound()->assertJsonPath('code', 'page.not_found');
    });
});

describe('caching', function () {
    it('tags every public read and answers 304 to a matching tag, a tag per language', function () {
        foreach (['/api/v1/products', '/api/v1/categories', '/api/v1/brands', '/api/v1/geo/divisions', '/api/v1/pages/about', '/api/v1/search?q=panjabi'] as $url) {
            $first = $this->getJson($url)->assertOk();
            $tag = $first->headers->get('ETag');
            expect($tag)->not->toBeNull($url);
            $this->getJson($url, ['If-None-Match' => $tag])->assertStatus(304);
            expect($this->getJson($url, ['Accept-Language' => 'bn'])->headers->get('ETag'))->not->toBe($tag);
        }
    });
});
