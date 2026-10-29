<?php

declare(strict_types=1);

use App\Modules\Analytics\Jobs\PruneAnalyticsEvents;
use App\Modules\Catalog\Database\Seeders\DemoCatalogSeeder;
use App\Modules\Cms\Database\Seeders\DemoCmsSeeder;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed([LanguageSeeder::class]);
});

function beacon(object $test, array $payload, array $headers = []): TestResponse
{
    return $test->call('POST', '/en/analytics/events', [], [], [], array_merge(['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], $headers), json_encode($payload));
}

it('records the three known events with nothing about the visitor', function () {
    beacon($this, ['name' => 'page_view', 'path' => '/en/shop?q=secret&token=abc#x'])->assertNoContent();
    beacon($this, ['name' => 'view_item', 'path' => '/en/p/cotton-panjabi', 'product' => '01HZXYZABCDEFGHJKMNPQRSTVW'])->assertNoContent();
    beacon($this, ['name' => 'search', 'path' => '/en/search', 'results' => 12])->assertNoContent();

    $rows = DB::table('analytics_events')->orderBy('id')->get();
    expect($rows->pluck('name')->all())->toBe(['page_view', 'view_item', 'search'])
        ->and($rows[0]->path)->toBe('/en/shop')                                  // the query string is never stored
        ->and($rows[1]->product_public_id)->toBe('01hzxyzabcdefghjkmnpqrstvw')
        ->and($rows[2]->result_count)->toBe(12)
        ->and($rows[0]->locale)->toBe('en');
    expect(Schema::getColumnListing('analytics_events'))->toBe(['id', 'name', 'locale', 'path', 'product_public_id', 'result_count', 'created_at']); // no user, session, cookie, IP or user-agent
});

it('refuses anything that is not one of those events', function () {
    beacon($this, ['name' => 'purchase', 'path' => '/en'])->assertStatus(422);
    beacon($this, ['name' => 'page_view', 'path' => 'https://evil.example/'])->assertStatus(422);
    beacon($this, ['name' => 'page_view'])->assertStatus(422);
    beacon($this, ['name' => 'page_view', 'path' => '/en', 'product' => 'not-a-ulid'])->assertStatus(422);
    $this->call('POST', '/en/analytics/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], 'not json')->assertStatus(422);

    expect(DB::table('analytics_events')->count())->toBe(0);
});

it('honours Do-Not-Track and Global Privacy Control on the server too', function () {
    beacon($this, ['name' => 'page_view', 'path' => '/en'], ['HTTP_DNT' => '1'])->assertNoContent();
    beacon($this, ['name' => 'page_view', 'path' => '/en'], ['HTTP_SEC_GPC' => '1'])->assertNoContent();

    expect(DB::table('analytics_events')->count())->toBe(0);
});

it('does not accept events from another site', function () {
    $this->call('POST', '/en/analytics/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'cross-site'], json_encode(['name' => 'page_view', 'path' => '/en']))->assertStatus(403);
    $this->call('POST', '/en/analytics/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://evil.example'], json_encode(['name' => 'page_view', 'path' => '/en']))->assertStatus(403);

    expect(DB::table('analytics_events')->count())->toBe(0);
});

it('throttles event floods', function () {
    foreach (range(1, 120) as $_) {
        beacon($this, ['name' => 'page_view', 'path' => '/en'])->assertNoContent();
    }

    beacon($this, ['name' => 'page_view', 'path' => '/en'])->assertStatus(429);
});

it('deletes raw events after 90 days', function () {
    DB::table('analytics_events')->insert([['name' => 'page_view', 'locale' => 'en', 'path' => '/old', 'created_at' => now()->subDays(100)], ['name' => 'page_view', 'locale' => 'en', 'path' => '/new', 'created_at' => now()]]);

    (new PruneAnalyticsEvents)->handle();

    expect(DB::table('analytics_events')->pluck('path')->all())->toBe(['/new']);
});

describe('storefront pages', function () {
    beforeEach(function () {
        Storage::fake('media');
        config(['catalog.media.disk' => 'media']);
        $this->seed([TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, DemoCatalogSeeder::class, DemoCmsSeeder::class]);
    });

    it('ask for consent in the visitor\'s language and send nothing on their own', function () {
        $this->get('/en')->assertSee('id="privacy-controls"', false)->assertSee('Help us improve the store?')->assertSee('Allow')->assertSee('No thanks');
        $this->get('/bn')->assertSee('অনুমতি দিন')->assertSee('গোপনীয়তা সেটিংস');
        expect(DB::table('analytics_events')->count())->toBe(0); // rendering a page records nothing server-side
    });

    it('declare what to count in meta tags: the product on a product page, the result count on page one of a search', function () {
        $id = DB::table('products')->whereIn('id', DB::table('product_variants')->where('sku', 'PANJ-001')->select('product_id'))->value('public_id');

        $this->get('/en/p/cotton-panjabi')->assertSee('<meta name="velmora:item" content="'.$id.'">', false);
        $this->get('/en/shop')->assertDontSee('velmora:item', false)->assertDontSee('velmora:search', false);
    });

    it('load nothing from other sites', function () {
        foreach (['/en', '/bn', '/en/shop', '/en/c/clothing', '/en/p/cotton-panjabi', '/en/pages/about'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            preg_match_all('#<(?:script|link|img|iframe)\b[^>]*\b(?:src|href)="(https?://[^"]+)"#i', (string) $html, $m);
            $foreign = array_filter($m[1], fn ($u) => parse_url($u, PHP_URL_HOST) !== parse_url(url('/'), PHP_URL_HOST) && ! str_contains($u, 'schema.org'));
            $scripts = array_filter($foreign, fn ($u) => true);

            expect(array_values($scripts))->toBe([], "{$path} loads from another host");
        }
    });

    it('keep the admin panel free of the consent banner', function () {
        $this->get('/admin/login')->assertOk()->assertDontSee('privacy-controls', false);
    });
});
