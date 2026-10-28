<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Search\Contracts\CatalogSearch;
use App\Modules\Search\Database\Seeders\SearchSynonymSeeder;
use App\Modules\Search\Jobs\PruneSearchQueries;
use App\Modules\Search\Services\SearchIndexer;
use App\Modules\Search\Services\TextNormalizer;
use App\Modules\Search\Services\TypoCorrector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FULLTEXT only sees COMMITTED rows, so these tests run in the Database suite (real commits, tables truncated between tests).

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->actor = Actor::system('t');
    $this->category = app(SaveCategory::class)->handle($this->actor, null, null, [new TranslationInput('en', 'General', 'general')]);

    $this->make = function (string $en, string $bn, string $sku, int $taka = 500, string $description = '', bool $publish = true): Product {
        $product = app(SaveProduct::class)->handle($this->actor, null, new ProductData(
            ProductType::Physical,
            [new TranslationInput('en', $en, null, $description === '' ? null : "<p>{$description}</p>"), new TranslationInput('bn', $bn)],
            categoryIds: [$this->category->id], defaultVariant: new VariantData($sku),
        ));
        app(SetRegularPrice::class)->handle($this->actor, (int) $product->variants()->value('id'), Money::of($taka * 100));
        if ($publish) {
            app(TransitionProduct::class)->publish($this->actor, $product);
        }

        return $product;
    };

    ($this->make)('Mobile Phone X1', 'মোবাইল ফোন এক্স১', 'MOB-X1', 15000, 'Great battery 1500 mAh');
    ($this->make)('Headphone Pro', 'হেডফোন প্রো', 'HP-PRO', 2500);
    ($this->make)('Cotton Panjabi', 'সুতির পাঞ্জাবি', 'PANJ-1', 1850, 'Soft cotton for the festival');
    ($this->make)('Jamdani Saree', 'জামদানি শাড়ি', 'SAREE-1', 8500);
    ($this->make)('Phone Case', 'ফোন কেস', 'CASE-1', 350);
    ($this->make)('Leather Wallet', 'চামড়ার মানিব্যাগ', 'WAL-1', 950);

    $this->names = fn (string $q, string $locale = 'en') => array_map(
        fn ($id) => (string) DB::table('product_translations')->where('product_id', $id)->where('locale', 'en')->value('name'),
        app(CatalogSearch::class)->search($q, $locale)->productIds,
    );
});

it('ranks title prefix above word matches above loose "contains" matches', function () {
    expect(($this->names)('phone'))->toBe(['Phone Case', 'Mobile Phone X1', 'Headphone Pro']);
});

it('finds a product by SKU, with or without punctuation, ahead of everything else', function () {
    expect(($this->names)('MOB-X1')[0])->toBe('Mobile Phone X1')->and(($this->names)('mob x1')[0])->toBe('Mobile Phone X1')->and(($this->names)('mobx1')[0])->toBe('Mobile Phone X1');
    expect(($this->names)('pan')[0])->toBe('Cotton Panjabi'); // SKU prefix PANJ-1 or title word: both lead here
});

it('searches Bangla, including a word inside a compound', function () {
    $bn = fn (string $q) => ($this->names)($q, 'bn');

    expect($bn('পাঞ্জাবি'))->toBe(['Cotton Panjabi'])
        ->and($bn('শাড়ি'))->toBe(['Jamdani Saree'])
        ->and($bn('ফোন')[0])->toBe('Phone Case')              // title starts with the word
        ->and($bn('ফোন'))->toContain('Mobile Phone X1', 'Headphone Pro'); // Headphone: ফোন inside হেডফোন (loose pass)
});

it('finds English names from the Bangla site and Bangla names from the English site', function () {
    expect(($this->names)('panjabi', 'bn'))->toBe(['Cotton Panjabi'])->and(($this->names)('জামদানি', 'en'))->toBe(['Jamdani Saree']);
});

it('reads Bangla digits, ignores invisible characters and punctuation, and cannot be injected into', function () {
    expect(($this->names)('১৫০০'))->toBe(['Mobile Phone X1'])
        ->and(($this->names)("pan\u{200D}jabi"))->toBe(['Cotton Panjabi'])
        ->and(($this->names)('"phone" +case* ~ @ < > %_ ;--'))->toContain('Phone Case');

    expect(TextNormalizer::text("  Ｐhone—CASE!! \u{200B}১২ "))->toBe('phone case 12');
});

it('matches words that FULLTEXT cannot index: two-letter words and stopwords', function () {
    expect(($this->names)('x1')[0])->toBe('Mobile Phone X1')            // 2 letters
        ->and(($this->names)('for the festival'))->toBe(['Cotton Panjabi']);   // stopwords "for", "the" must not break the query
});

it('widens a search with admin-managed synonyms, in both directions', function () {
    expect(($this->names)('cellphone'))->toBe([]);

    DB::table('search_synonyms')->insert(['term' => 'cellphone', 'synonym' => 'mobile', 'created_at' => now(), 'updated_at' => now()]);

    expect(($this->names)('cellphone'))->toContain('Mobile Phone X1')->and(($this->names)('cellphone', 'bn'))->toContain('Mobile Phone X1');
    $this->seed(SearchSynonymSeeder::class);
    expect(($this->names)('মোবাইল', 'en'))->toContain('Mobile Phone X1');
});

it('corrects a misspelt word against the indexed vocabulary and says so', function () {
    $r = app(CatalogSearch::class)->search('panjabbi', 'en');
    expect($r->correctedQuery)->toBe('panjabi')->and(count($r->productIds))->toBe(1);

    expect(app(CatalogSearch::class)->search('jamdni', 'en')->correctedQuery)->toBe('jamdani');
    expect(app(CatalogSearch::class)->search('qqqzzzxxx', 'en'))->toHaveProperty('productIds', [])->and(app(CatalogSearch::class)->search('qqqzzzxxx', 'en')->correctedQuery)->toBeNull();
    expect(TypoCorrector::distance('পাঞ্জাবি', 'পাঞ্জাবী'))->toBe(1);
});

it('keeps unpublished and draft products out of search, and removes a product when it is unpublished', function () {
    ($this->make)('Secret Draft Gadget', 'গোপন', 'DRAFT-1', 100, '', publish: false);
    expect(($this->names)('gadget'))->toBe([]);

    app(TransitionProduct::class)->unpublish($this->actor, Product::query()->whereHas('translations', fn ($q) => $q->where('name', 'Phone Case'))->firstOrFail());
    expect(($this->names)('case'))->toBe([]);
});

it('rebuilds the whole index on demand', function () {
    DB::table('search_index')->delete();
    DB::table('search_skus')->delete();

    $this->artisan('search:reindex')->assertSuccessful();

    expect(DB::table('search_index')->distinct()->count('product_id'))->toBe(6)->and(DB::table('search_index')->count())->toBe(12)->and(($this->names)('wallet'))->toBe(['Leather Wallet']);
});

it('gives fast, index-backed lookups', function () {
    $plan = DB::select("EXPLAIN SELECT product_id FROM search_index WHERE locale = 'en' AND MATCH(title, body) AGAINST ('+phone*' IN BOOLEAN MODE)");
    expect(strtolower((string) ($plan[0]->key ?? '')))->toContain('title');

    $sku = DB::select("EXPLAIN SELECT product_id FROM search_skus WHERE sku_norm LIKE 'mob%'");
    expect($sku[0]->key)->toBe('search_skus_sku_norm_index');
});

describe('storefront', function () {
    it('shows ranked results with filters, and logs the search without any visitor data', function () {
        $this->get('/en/search?q=phone')->assertOk()->assertSeeInOrder(['Phone Case', 'Mobile Phone X1', 'Headphone Pro'])->assertSee('3 products');
        $this->get('/en/search?q=phone&sort=price_asc')->assertOk()->assertSeeInOrder(['Phone Case', 'Headphone Pro', 'Mobile Phone X1']);
        $this->get('/en/search?q=phone&min=3000')->assertOk()->assertSee('Mobile Phone X1')->assertDontSee('Phone Case');

        $log = DB::table('search_queries')->get();
        expect($log)->toHaveCount(3)->and($log[0]->query)->toBe('phone')->and($log[0]->result_count)->toBe(3);
        expect(array_diff(Schema::getColumnListing('search_queries'), ['id', 'query', 'locale', 'result_count', 'created_at']))->toBe([]); // no user, session, IP or cookie columns
    });

    it('records searches that found nothing, and shows the corrected spelling notice', function () {
        $this->get('/en/search?q=zzzzqq')->assertOk()->assertSee('No products found');
        $this->get('/en/search?q=panjabbi')->assertOk()->assertSee('Showing results for “panjabi”')->assertSee('Cotton Panjabi');

        expect(DB::table('search_queries')->where('result_count', 0)->pluck('query')->all())->toBe(['zzzzqq']);
    });

    it('does not log later result pages, blank or junk searches, and tolerates any input', function () {
        $this->get('/en/search?q=phone&page=2')->assertOk();
        $this->get('/en/search?q=')->assertOk();
        $this->get('/en/search')->assertOk();
        $this->get('/en/search?q[]=x&sort[]=1&page=-1&min[]=1&max[]=2&brand=x&attr=1')->assertOk();
        $this->getJson('/en/search/suggest?q[]=x')->assertOk();
        $this->get('/en/search?q='.str_repeat('a', 5000))->assertOk();

        expect(DB::table('search_queries')->count())->toBe(0); // junk, blank and absurdly long words are ignored, not logged
    });

    it('answers the Bangla page in Bangla', function () {
        $this->get('/bn/search?q='.rawurlencode('পাঞ্জাবি'))->assertOk()->assertSee('সুতির পাঞ্জাবি')->assertSee('১টি পণ্য');
    });

    it('serves autocomplete as small JSON, only for two or more letters', function () {
        $this->getJson('/en/search/suggest?q=pho')->assertOk()->assertJsonPath('items.0.name', 'Phone Case')->assertJsonPath('items.0.url', url('/en/p/phone-case'))->assertHeader('Cache-Control', 'max-age=60, public');
        $this->getJson('/en/search/suggest?q=p')->assertOk()->assertExactJson(['items' => []]);
        $this->getJson('/bn/search/suggest?q='.rawurlencode('ফোন'))->assertOk()->assertJsonPath('items.0.name', 'ফোন কেস');
        expect(DB::table('search_queries')->count())->toBe(0); // suggestions are never logged
    });

    it('throttles searching', function () {
        for ($i = 0; $i < 60; $i++) {
            $this->get('/en/search?q=phone')->assertOk();
        }

        $this->get('/en/search?q=phone')->assertStatus(429);
    });

    it('renders the search box as a working form and a labelled combobox', function () {
        $this->get('/en')->assertOk()->assertSee('role="search"', false)->assertSee('role="combobox"', false)->assertSee('aria-controls="search-suggestions"', false)->assertSee('action="'.url('/en/search').'"', false);
    });
});

describe('admin', function () {
    beforeEach(fn () => $this->withoutMiddleware(EnforceSessionPolicy::class));

    it('lets merchandisers manage synonyms, normalising and de-duplicating pairs', function () {
        $staff = catalogStaff(['catalog.view', 'catalog.update']);

        $this->actingAs($staff, 'admin')->post('/admin/search/synonyms', ['term' => ' Sneakers ', 'synonym' => 'জুতা'])->assertRedirect();
        $this->actingAs($staff, 'admin')->post('/admin/search/synonyms', ['term' => 'জুতা', 'synonym' => 'sneakers'])->assertSessionHasErrors('term'); // same pair, other direction
        $this->actingAs($staff, 'admin')->post('/admin/search/synonyms', ['term' => 'same', 'synonym' => 'SAME'])->assertSessionHasErrors('term');

        expect(DB::table('search_synonyms')->where('term', 'sneakers')->value('synonym'))->toBe('জুতা');
        $this->actingAs($staff, 'admin')->get('/admin/search/synonyms?q=sneak')->assertOk()->assertSee('জুতা');

        $this->actingAs($staff, 'admin')->delete('/admin/search/synonyms/'.DB::table('search_synonyms')->value('id'))->assertRedirect();
        expect(DB::table('search_synonyms')->count())->toBe(0);
    });

    it('lists searches with no results, most frequent first', function () {
        foreach (['zzz', 'zzz', 'yyy'] as $q) {
            DB::table('search_queries')->insert(['query' => $q, 'locale' => 'en', 'result_count' => 0, 'created_at' => now()]);
        }
        DB::table('search_queries')->insert(['query' => 'phone', 'locale' => 'en', 'result_count' => 3, 'created_at' => now()]);
        DB::table('search_queries')->insert(['query' => 'old', 'locale' => 'en', 'result_count' => 0, 'created_at' => now()->subDays(40)]);

        $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/search/zero-results')->assertOk()->assertSeeInOrder(['zzz', 'yyy'])->assertDontSee('>old<', false)->assertDontSee('>phone<', false);
        $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/search/zero-results?days=90')->assertOk()->assertSee('>old<', false);
    });

    it('prunes old search logs', function () {
        DB::table('search_queries')->insert([['query' => 'old', 'locale' => 'en', 'result_count' => 0, 'created_at' => now()->subDays(100)], ['query' => 'new', 'locale' => 'en', 'result_count' => 0, 'created_at' => now()]]);

        (new PruneSearchQueries)->handle();

        expect(DB::table('search_queries')->pluck('query')->all())->toBe(['new']);
    });

    it('gates the search admin screens by permission', function () {
        $this->actingAs(catalogStaff(['pricing.view']), 'admin')->get('/admin/search/synonyms')->assertForbidden();
        $this->actingAs(catalogStaff(['catalog.view']), 'admin')->post('/admin/search/synonyms', ['term' => 'a1', 'synonym' => 'b1'])->assertForbidden();
        $this->actingAs(catalogStaff(['pricing.view']), 'admin')->get('/admin/search/zero-results')->assertForbidden();
    });
});

it('seeds a starter list of English and Bangla synonyms, all normalised', function () {
    $this->seed(SearchSynonymSeeder::class);
    $before = DB::table('search_synonyms')->count();
    $this->seed(SearchSynonymSeeder::class);

    expect($before)->toBeGreaterThan(50)->and(DB::table('search_synonyms')->count())->toBe($before);
    foreach (DB::table('search_synonyms')->get() as $row) {
        expect($row->term)->toBe(TextNormalizer::text($row->term))->and($row->synonym)->toBe(TextNormalizer::text($row->synonym));
    }
    expect(app(SearchIndexer::class))->toBeInstanceOf(SearchIndexer::class);
});
