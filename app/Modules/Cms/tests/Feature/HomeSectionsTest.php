<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\HomeCollections;
use App\Modules\Catalog\Database\Seeders\DemoCatalogSeeder;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Cms\Actions\SaveHomeSection;
use App\Modules\Cms\Contracts\HomeLayout;
use App\Modules\Cms\Database\Seeders\DemoCmsSeeder;
use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Cms\Services\TranslationGaps;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\FrozenClock;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Phase 10, slice 3c-2: the blocks of the home page. With none visible the home page keeps its built-in layout.
 */

beforeEach(function () {
    Storage::fake('media');
    config(['catalog.media.disk' => 'media']);
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, RolesAndPermissionsSeeder::class, DemoCatalogSeeder::class, DemoCmsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->clock = new FrozenClock(CarbonImmutable::parse('2026-12-01 06:00:00', 'UTC'));
    $this->travelTo(CarbonImmutable::parse('2026-12-01 06:00:00', 'UTC'));
    $this->app->instance(Clock::class, $this->clock);
    $this->actor = Actor::system('t');
    $this->staff = catalogStaff(['cms.view', 'cms.edit', 'cms.publish']);

    $this->at = function (string $utc): void {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
        $this->clock->travelTo(CarbonImmutable::parse($utc, 'UTC'));
        app(StorefrontCache::class)->bump();   // what the cache's own 120-second expiry would do
    };
    $this->refresh = fn () => app(StorefrontCache::class)->bump();
    /** Makes a section. $o: ref, active, can_show, starts, ends. Returns its public id. */
    $this->section = fn (string $type, array $texts = [], array $o = []) => app(SaveHomeSection::class)->handle(
        $this->actor, null, $type, $o['ref'] ?? null, $o['active'] ?? true, $o['can_show'] ?? true, $o['starts'] ?? null, $o['ends'] ?? null, $texts,
    );
    $this->en = fn (string $title, ?string $body = null) => ['en' => ['title' => $title, 'body' => $body]];
    $this->col = fn (string $slug) => (string) Collection::query()->whereKey(DB::table('collection_translations')->where('slug', $slug)->value('collection_id'))->value('public_id');
    $this->colId = fn (string $slug) => (int) DB::table('collection_translations')->where('slug', $slug)->value('collection_id');
    $this->home = fn (string $locale = 'en') => $this->get("/{$locale}")->assertOk()->getContent();
    $this->pos = fn (string $html, string $needle) => (int) strpos($html, $needle);
});

describe('the home page', function () {
    it('keeps its built-in layout while no block is visible', function () {
        $html = ($this->home)();
        expect($html)->toContain('Shop by category')->toContain('New arrivals')->toContain('Popular right now')->toContain('Everyday essentials, delivered');

        ($this->section)('text', ($this->en)('Hidden', '<p>nope</p>'), ['active' => false]);
        ($this->section)('text', ($this->en)('Future', '<p>later</p>'), ['starts' => '2026-12-05T12:00']);
        ($this->section)('text', ($this->en)('Past', '<p>gone</p>'), ['starts' => '2026-11-01T12:00', 'ends' => '2026-11-02T12:00']);
        ($this->refresh)();

        expect(($this->home)())->toContain('Shop by category')->toContain('New arrivals')->toContain('Popular right now')->not->toContain('nope')->not->toContain('later')->not->toContain('gone');
    });

    it('shows the visible blocks in order and nothing else', function () {
        ($this->section)('new_arrivals', ($this->en)('Fresh in'));
        ($this->section)('text', ($this->en)('Hello there', '<p>Welcome text</p>'));
        ($this->section)('categories', []);
        ($this->refresh)();

        $html = ($this->home)();
        expect($html)->toContain('Fresh in')->toContain('Hello there')->toContain('Welcome text')->toContain('Shop by category')
            ->and(($this->pos)($html, 'Fresh in'))->toBeLessThan(($this->pos)($html, 'Hello there'))->and(($this->pos)($html, 'Hello there'))->toBeLessThan(($this->pos)($html, 'Shop by category'));
        expect($html)->not->toContain('Popular right now')->not->toContain('Everyday essentials, delivered')->not->toContain('data-section-none');   // the hero and popular blocks were not set up
    });

    it('uses the usual headings when a block has no heading of its own', function () {
        ($this->section)('new_arrivals', []);
        ($this->section)('popular', []);
        ($this->refresh)();

        expect(($this->home)())->toContain('New arrivals')->toContain('Popular right now');
        expect(($this->home)('bn'))->toContain('নতুন পণ্য');
    });

    it('shows the banners of the main and the promotion blocks, and leaves out a banner block with no banners', function () {
        ($this->section)('banner_hero', []);
        ($this->section)('categories', []);
        ($this->refresh)();
        expect(($this->home)())->toContain('Everyday essentials, delivered')->toContain('Eid specials')->toContain('fetchpriority="high"');

        DB::table('banners')->where('placement', 'home_hero')->update(['placement' => 'home_strip']);   // no main banners left
        ($this->section)('banner_strip', []);
        ($this->refresh)();
        $html = ($this->home)();
        expect($html)->toContain('Everyday essentials, delivered')->toContain('Shop by category');
        expect($html)->not->toContain('fetchpriority="high"');

        DB::table('banners')->update(['is_active' => false]);   // no banners at all: those blocks are left out, the page still shows the rest
        ($this->refresh)();
        expect(($this->home)())->not->toContain('Everyday essentials, delivered')->toContain('Shop by category');
    });

    it('shows a collection with its products, under its own heading or the collection\'s name', function () {
        $ref = ($this->col)('eid-specials');
        ($this->section)('collection', [], ['ref' => $ref]);
        ($this->refresh)();

        $html = ($this->home)();
        expect($html)->toContain('Eid specials')->toContain('Cotton Panjabi')->toContain('/en/collections/eid-specials')->not->toContain('Steel Water Bottle');
        expect(($this->home)('bn'))->toContain('ঈদ স্পেশাল')->toContain('সুতির পাঞ্জাবি');

        $id = DB::table('cms_home_sections')->where('collection_ref', $ref)->value('id');
        app(SaveHomeSection::class)->handle($this->actor, (string) DB::table('cms_home_sections')->where('id', $id)->value('public_id'), 'collection', $ref, null, true, null, null, ($this->en)('Panjabi picks'));
        expect(($this->home)())->toContain('Panjabi picks');
    });

    it('leaves out a collection that is switched off, deleted, empty or gone, and falls back to the usual page when nothing else is left', function () {
        $eid = ($this->col)('eid-specials');
        $ghost = strtolower((string) Str::ulid());
        $id = ($this->section)('collection', [], ['ref' => $eid]);
        $row = (int) DB::table('cms_home_sections')->where('public_id', $id)->value('id');
        $usual = fn () => expect(($this->home)())->toContain('Popular right now');   // only the built-in layout has this block: a collection-only layout never would

        ($this->refresh)();
        expect(($this->home)())->not->toContain('Popular right now');                // the block works while its collection does

        DB::table('cms_home_sections')->where('id', $row)->update(['collection_ref' => $ghost]);   // a collection that no longer exists
        ($this->refresh)();
        $usual();

        DB::table('cms_home_sections')->where('id', $row)->update(['collection_ref' => $eid]);
        DB::table('collections')->where('id', ($this->colId)('eid-specials'))->update(['is_active' => false]);
        ($this->refresh)();
        $usual();

        DB::table('collections')->where('id', ($this->colId)('eid-specials'))->update(['is_active' => true, 'deleted_at' => now()]);
        ($this->refresh)();
        $usual();

        DB::table('collections')->where('id', ($this->colId)('eid-specials'))->update(['deleted_at' => null]);
        DB::table('collection_products')->where('collection_id', ($this->colId)('eid-specials'))->delete();   // nothing in it
        ($this->refresh)();
        $usual();
    });

    it('shows text in the visitor\'s language, falls back to English, and never lets a script or unsafe link through', function () {
        ($this->section)('text', ['en' => ['title' => 'About us', 'body' => '<p>English story</p><script>alert(1)</script><a href="javascript:alert(2)">bad</a><a href="https://example.com/x">good</a>'], 'bn' => ['title' => 'আমাদের কথা', 'body' => '<p>বাংলা গল্প</p>']]);
        ($this->section)('text', ($this->en)('Heading in English', '<p>Only English body</p>'));
        ($this->refresh)();

        $en = ($this->home)();
        expect($en)->toContain('English story')->toContain('https://example.com/x')->not->toContain('<script>')->not->toContain('javascript:');
        $bn = ($this->home)('bn');
        expect($bn)->toContain('আমাদের কথা')->toContain('বাংলা গল্প')->toContain('Heading in English')->toContain('Only English body');   // the second block has no Bangla: its English heading and text are shown
    });

    it('cleans a text block again on the way out, even one that got into the database unclean', function () {
        $id = ($this->section)('text', ($this->en)('Raw', '<p>Safe</p>'));
        DB::table('cms_home_section_translations')->where('section_id', DB::table('cms_home_sections')->where('public_id', $id)->value('id'))->update(['body' => '<p>Safe</p><script>alert(1)</script><img src=x onerror=alert(2)>']);
        ($this->refresh)();

        $html = ($this->home)();
        expect($html)->toContain('Safe')->not->toContain('<script>')->not->toContain('onerror');
    });

    it('leaves out a text block that has no text in any language', function () {
        $id = ($this->section)('text', ($this->en)('Empty', '<p>x</p>'));
        DB::table('cms_home_section_translations')->where('section_id', DB::table('cms_home_sections')->where('public_id', $id)->value('id'))->update(['body' => null]);
        ($this->refresh)();

        expect(($this->home)())->toContain('Popular right now')->not->toContain('Empty');   // nothing left to show: the usual page
    });

    it('shows a block only inside its window: the start is included, the end is not', function () {
        ($this->section)('text', ($this->en)('Flash notice', '<p>Sale on</p>'), ['starts' => '2026-12-02T12:00', 'ends' => '2026-12-03T12:00']);   // 06:00 to 06:00 UTC next day

        ($this->at)('2026-12-02 05:59:59');
        expect(($this->home)())->not->toContain('Sale on');
        ($this->at)('2026-12-02 06:00:00');
        expect(($this->home)())->toContain('Sale on');
        ($this->at)('2026-12-03 05:59:59');
        expect(($this->home)())->toContain('Sale on');
        ($this->at)('2026-12-03 06:00:00');
        expect(($this->home)())->not->toContain('Sale on');
    });

    it('costs the same number of queries with one block or many, once the page is cached', function () {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->home)();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        ($this->section)('text', ($this->en)('One', '<p>one</p>'));
        ($this->home)();
        $few = $count();
        foreach (range(1, 12) as $i) {
            ($this->section)($i % 2 === 0 ? 'text' : 'new_arrivals', ($this->en)("Block {$i}", '<p>b</p>'));
        }
        ($this->home)();

        expect($count())->toBe($few);
    });
});

describe('saving a block', function () {
    it('refuses what cannot be saved', function () {
        $save = fn (string $type, array $o = [], array $texts = []) => fn () => ($this->section)($type, $texts, $o);
        $eid = ($this->col)('eid-specials');

        expect($save('carousel'))->toThrow(CmsException::class, 'home_invalid')
            ->and($save('collection'))->toThrow(CmsException::class, 'home_collection_missing')
            ->and($save('collection', ['ref' => strtolower((string) Str::ulid())]))->toThrow(CmsException::class, 'home_collection_missing')
            ->and($save('text', [], ['en' => ['title' => 'No body']]))->toThrow(CmsException::class, 'home_body_required')
            ->and($save('text', [], ['en' => ['body' => '<script>x</script>'], 'bn' => ['body' => '<p>বাংলা</p>']]))->toThrow(CmsException::class, 'home_body_required')   // nothing is left after cleaning
            ->and($save('text', [], ['bn' => ['body' => '<p>বাংলা</p>']]))->toThrow(CmsException::class, 'home_body_required')                                              // English is required
            ->and($save('text', [], ['en' => ['body' => str_repeat('a', SaveHomeSection::BODY_MAX + 1)]]))->toThrow(CmsException::class, 'home_too_long')
            ->and($save('categories', ['starts' => '2026-12-05T12:00', 'ends' => '2026-12-05T12:00']))->toThrow(CmsException::class, 'bad_window')
            ->and($save('categories', ['starts' => '2026-12-05T12:00', 'ends' => '2026-12-04T12:00']))->toThrow(CmsException::class, 'bad_window')
            ->and(DB::table('cms_home_sections')->count())->toBe(0);

        DB::table('collections')->where('public_id', $eid)->update(['is_active' => false]);
        expect($save('collection', ['ref' => $eid]))->toThrow(CmsException::class, 'home_collection_missing');   // switched off
        ($this->section)('text', ['en' => ['body' => str_repeat('a', SaveHomeSection::BODY_MAX)]]);               // exactly the limit is fine
        expect(DB::table('cms_home_sections')->count())->toBe(1);
    });

    it('holds at most twenty blocks, but lets an existing one be edited', function () {
        $first = ($this->section)('categories', []);
        foreach (range(2, SaveHomeSection::MAX_SECTIONS) as $i) {
            ($this->section)('categories', []);
        }

        expect(fn () => ($this->section)('popular', []))->toThrow(CmsException::class, 'home_full')->and(DB::table('cms_home_sections')->count())->toBe(20);
        app(SaveHomeSection::class)->handle($this->actor, $first, 'categories', null, null, true, null, null, ($this->en)('Edited'));
        expect(DB::table('cms_home_section_translations')->where('title', 'Edited')->count())->toBe(1);
    });

    it('keeps a block\'s type, forgets the collection of a block that is not one, and drops a cleared language', function () {
        $id = ($this->section)('text', ['en' => ['title' => 'T', 'body' => '<p>x</p>'], 'bn' => ['title' => 'ট', 'body' => '<p>ব</p>']]);

        expect(fn () => app(SaveHomeSection::class)->handle($this->actor, $id, 'categories', null, null, true, null, null, ($this->en)('T')))->toThrow(CmsException::class, 'home_invalid')
            ->and(fn () => app(SaveHomeSection::class)->handle($this->actor, strtolower((string) Str::ulid()), 'text', null, null, true, null, null, ($this->en)('T', '<p>x</p>')))->toThrow(CmsException::class, 'home_not_found');

        $cat = ($this->section)('categories', [], ['ref' => ($this->col)('eid-specials')]);   // a reference on the wrong type is ignored
        expect(DB::table('cms_home_sections')->where('public_id', $cat)->value('collection_ref'))->toBeNull();

        app(SaveHomeSection::class)->handle($this->actor, $id, 'text', null, null, true, null, null, ['en' => ['title' => 'T', 'body' => '<p>x</p>'], 'bn' => ['title' => '', 'body' => '']]);
        expect(DB::table('cms_home_section_translations')->where('section_id', DB::table('cms_home_sections')->where('public_id', $id)->value('id'))->pluck('locale')->all())->toBe(['en']);
    });

    it('starts hidden, and only a publisher shows or hides a block', function () {
        $id = ($this->section)('categories', [], ['active' => true, 'can_show' => false]);
        $on = fn () => (bool) DB::table('cms_home_sections')->where('public_id', $id)->value('is_active');
        $edit = fn (?bool $active, bool $canShow) => app(SaveHomeSection::class)->handle($this->actor, $id, 'categories', null, $active, $canShow, null, null, []);

        expect($on())->toBeFalse();
        $edit(true, false);
        expect($on())->toBeFalse();
        $edit(true, true);
        expect($on())->toBeTrue();
        $edit(null, true);
        expect($on())->toBeTrue();
        $edit(false, false);
        expect($on())->toBeTrue();
        $edit(false, true);
        expect($on())->toBeFalse();
    });

    it('stores the window in UTC from Dhaka time, and puts a new block last', function () {
        $a = ($this->section)('categories', [], ['starts' => '2026-12-05T12:00', 'ends' => '2026-12-06T18:30']);
        $b = ($this->section)('popular', []);

        $row = DB::table('cms_home_sections')->where('public_id', $a)->first();
        expect($row->starts_at)->toBe('2026-12-05 06:00:00.000000')->and($row->ends_at)->toBe('2026-12-06 12:30:00.000000')
            ->and((int) DB::table('cms_home_sections')->where('public_id', $a)->value('sort_order'))->toBe(1)->and((int) DB::table('cms_home_sections')->where('public_id', $b)->value('sort_order'))->toBe(2);
    });

    it('lets the database refuse the shapes the Action would', function () {
        $row = fn (array $o) => DB::table('cms_home_sections')->insert($o + ['public_id' => strtolower(substr(md5((string) random_int(0, PHP_INT_MAX)), 0, 26)), 'type' => 'categories', 'sort_order' => 1, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => $row(['type' => 'carousel']))->toThrow(QueryException::class)
            ->and(fn () => $row(['type' => 'collection']))->toThrow(QueryException::class)                                               // a collection block names a collection
            ->and(fn () => $row(['type' => 'popular', 'collection_ref' => str_repeat('a', 26)]))->toThrow(QueryException::class)         // and only a collection block does
            ->and(fn () => $row(['starts_at' => '2026-12-02 00:00:00', 'ends_at' => '2026-12-02 00:00:00']))->toThrow(QueryException::class)
            ->and(fn () => $row(['starts_at' => '2026-12-03 00:00:00', 'ends_at' => '2026-12-02 00:00:00']))->toThrow(QueryException::class);
    });
});

describe('ordering and removing', function () {
    beforeEach(function () {
        $this->move = fn (string $id, string $dir) => $this->actingAs($this->staff, 'admin')->post("/admin/cms/home/{$id}/move", ['direction' => $dir]);
        $this->order = fn () => DB::table('cms_home_sections')->orderBy('sort_order')->orderBy('id')->pluck('type')->all();
    });

    it('swaps a block with its neighbour, ignores a move off the end and tidies a duplicated order', function () {
        $a = ($this->section)('categories', []);
        $b = ($this->section)('popular', []);
        $c = ($this->section)('new_arrivals', []);

        ($this->move)($c, 'up')->assertRedirect();
        expect(($this->order)())->toBe(['categories', 'new_arrivals', 'popular']);
        ($this->move)($a, 'up')->assertRedirect();
        ($this->move)($b, 'down')->assertRedirect();
        expect(($this->order)())->toBe(['categories', 'new_arrivals', 'popular']);

        DB::table('cms_home_sections')->update(['sort_order' => 4]);
        ($this->move)($b, 'up')->assertRedirect();
        expect(DB::table('cms_home_sections')->orderBy('sort_order')->pluck('sort_order')->map(fn ($v) => (int) $v)->all())->toBe([1, 2, 3]);
    });

    it('changes the page at once, and refuses a bad direction or an unknown block', function () {
        $a = ($this->section)('text', ($this->en)('Alpha block', '<p>a</p>'));
        ($this->section)('text', ($this->en)('Beta block', '<p>b</p>'));
        $html = ($this->home)();   // cached
        expect(($this->pos)($html, 'Alpha block'))->toBeLessThan(($this->pos)($html, 'Beta block'));

        ($this->move)($a, 'down');
        $html = ($this->home)();
        expect(($this->pos)($html, 'Beta block'))->toBeLessThan(($this->pos)($html, 'Alpha block'));

        ($this->move)($a, 'sideways')->assertSessionHasErrors('direction');
        ($this->move)(str_repeat('0', 26), 'up')->assertNotFound();
    });

    it('removes a block, takes it off the page at once and audits it', function () {
        ($this->section)('text', ($this->en)('Stay', '<p>stay</p>'));
        $gone = ($this->section)('text', ($this->en)('Zebra block', '<p>z</p>'));
        expect(($this->home)())->toContain('Zebra block');

        $this->actingAs($this->staff, 'admin')->delete("/admin/cms/home/{$gone}")->assertRedirect('/admin/cms/home');

        expect(DB::table('cms_home_sections')->count())->toBe(1)->and(DB::table('cms_home_section_translations')->count())->toBe(1)->and(DB::table('audit_events')->where('action', 'cms.home_section_deleted')->count())->toBe(1);
        expect(($this->home)())->toContain('Stay')->not->toContain('Zebra block');
        $this->actingAs($this->staff, 'admin')->delete("/admin/cms/home/{$gone}")->assertNotFound();
    });

    it('shows a saved block to visitors at once', function () {
        ($this->section)('text', ($this->en)('Old news', '<p>old</p>'));
        expect(($this->home)())->toContain('Old news');

        ($this->section)('text', ($this->en)('Fresh news', '<p>fresh</p>'));
        expect(($this->home)())->toContain('Fresh news');
    });
});

describe('the staff screens', function () {
    it('lists the blocks with their states and an outline preview', function () {
        ($this->section)('text', ($this->en)('Live block', '<p>Rendered <b>text</b></p>'));
        DB::table('cms_home_section_translations')->update(['body' => '<p>Rendered <b>text</b></p><script>x</script>']);   // unclean text that got in some other way is cleaned in the preview too
        ($this->section)('categories', [], ['active' => false]);
        ($this->section)('popular', [], ['starts' => '2026-12-05T12:00']);
        ($this->section)('new_arrivals', [], ['starts' => '2026-11-01T12:00', 'ends' => '2026-11-02T12:00']);

        $this->actingAs($this->staff, 'admin')->get('/admin/cms/home')->assertOk()
            ->assertSee('Home page sections')->assertSee('Showing now')->assertSee('Hidden')->assertSee('Scheduled')->assertSee('Ended')->assertSee('Preview')
            ->assertSee('Rendered <b>text</b>', false)->assertDontSee('<script>', false)->assertSee('Live block');
        $this->actingAs($this->staff, 'admin')->get('/admin/cms/home/create')->assertOk()->assertSee('Collection (for a collection block)')->assertSee('Eid specials');
    });

    it('saves a block through the form, hidden for an editor who may not publish', function () {
        $editor = catalogStaff(['cms.view', 'cms.edit']);
        $this->actingAs($editor, 'admin')->get('/admin/cms/home/create')->assertOk()->assertSee('The block will start hidden.');
        $this->actingAs($editor, 'admin')->post('/admin/cms/home', ['type' => 'text', 'active_present' => '1', 'active' => '1', 'starts_local' => '2026-12-05T12:00', 'texts' => ['en' => ['title' => 'Hi', 'body' => '<p>Hello</p>'], 'bn' => ['title' => '', 'body' => '']]])->assertRedirect('/admin/cms/home');
        expect((bool) DB::table('cms_home_sections')->value('is_active'))->toBeFalse();

        $id = (string) DB::table('cms_home_sections')->value('public_id');
        $this->actingAs($this->staff, 'admin')->get("/admin/cms/home/{$id}/edit")->assertOk()->assertSee('Show this block')->assertSee('2026-12-05T12:00')->assertSee('Hello');
        $this->actingAs($this->staff, 'admin')->put("/admin/cms/home/{$id}", ['type' => 'text', 'active_present' => '1', 'active' => '1', 'texts' => ['en' => ['title' => 'Hi', 'body' => '<p>Hello</p>']]])->assertRedirect('/admin/cms/home');
        expect((bool) DB::table('cms_home_sections')->value('is_active'))->toBeTrue();
    });

    it('explains a refused save and keeps what was typed', function () {
        $this->actingAs($this->staff, 'admin')->from('/admin/cms/home/create')->post('/admin/cms/home', ['type' => 'text', 'texts' => ['en' => ['title' => 'No body']]])
            ->assertRedirect('/admin/cms/home/create')->assertSessionHasErrors('home')->assertSessionHasInput('texts.en.title', 'No body');
        $this->actingAs($this->staff, 'admin')->post('/admin/cms/home', ['type' => 'carousel'])->assertSessionHasErrors('type');
        $this->actingAs($this->staff, 'admin')->post('/admin/cms/home', ['type' => 'categories', 'starts_local' => '2026-12-05T12:00', 'ends_local' => '2026-12-04T12:00'])->assertSessionHasErrors('home');
        expect(DB::table('cms_home_sections')->count())->toBe(0);
    });

    it('gates every screen and action by permission', function () {
        $id = ($this->section)('categories', []);
        $viewer = catalogStaff(['cms.view']);
        $form = ['type' => 'categories'];

        $this->actingAs($viewer, 'admin')->get('/admin/cms/home')->assertOk();
        $this->actingAs($viewer, 'admin')->get("/admin/cms/home/{$id}/edit")->assertOk();
        $this->actingAs($viewer, 'admin')->get('/admin/cms/home/create')->assertForbidden();
        $this->actingAs($viewer, 'admin')->post('/admin/cms/home', $form)->assertForbidden();
        $this->actingAs($viewer, 'admin')->put("/admin/cms/home/{$id}", $form)->assertForbidden();
        $this->actingAs($viewer, 'admin')->delete("/admin/cms/home/{$id}")->assertForbidden();
        $this->actingAs($viewer, 'admin')->post("/admin/cms/home/{$id}/move", ['direction' => 'up'])->assertForbidden();
        $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/cms/home')->assertForbidden();
        $this->actingAs(catalogStaff(['cms.publish']), 'admin')->post('/admin/cms/home', $form)->assertForbidden();
        expect(DB::table('cms_home_sections')->count())->toBe(1);

        auth('admin')->logout();
        $this->flushSession();
        $this->get('/admin/cms/home')->assertRedirect();
    });

    it('reads in Bangla and keeps the two language files in step', function () {
        ($this->section)('categories', []);
        $this->staff->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($this->staff->fresh(), 'admin')->get('/admin/cms/home')->assertOk()->assertSee('হোম পাতার অংশ')->assertSee('ক্যাটাগরি')->assertSee('লুকানো');
        $this->actingAs($this->staff->fresh(), 'admin')->get('/admin/cms/home/create')->assertOk()->assertSee('ধরন');

        $keys = function (array $a, string $prefix = '') use (&$keys): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? array_push($out, ...$keys($v, "{$prefix}{$k}.")) : $out[] = "{$prefix}{$k}";
            }

            return $out;
        };
        foreach (['home', 'gaps'] as $file) {
            $en = $keys(require app_path("Modules/Cms/resources/lang/en/{$file}.php"));
            $bn = $keys(require app_path("Modules/Cms/resources/lang/bn/{$file}.php"));
            expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
        }
    });

    it('joins the missing-translation dashboard for the blocks that are shown', function () {
        ($this->section)('text', ($this->en)('Needs Bangla', '<p>English only</p>'));
        ($this->section)('text', ($this->en)('Hidden one', '<p>x</p>'), ['active' => false]);
        ($this->section)('categories', []);   // no text at all: nothing to translate

        $gaps = app(TranslationGaps::class)->forLocale('bn')['home_sections'];   // the categories block has no text, so there is nothing to translate there
        expect($gaps['live'])->toBe(1)->and($gaps['missing'])->toBe(1)->and($gaps['rows'][0])->toMatchArray(['title' => 'Needs Bangla', 'fields' => ['title', 'body']])->and($gaps['route'])->toBe('admin.cms.home.edit');
        $this->actingAs($this->staff, 'admin')->get('/admin/cms/translations')->assertOk()->assertSee('Home page blocks');
    });
});

describe('what the home page can offer', function () {
    it('lists only active collections, in the visitor\'s language, and says which exist', function () {
        $options = app(HomeCollections::class)->options('en');
        expect($options)->toHaveCount(2)->and(array_values($options))->toBe(['Eid specials', 'Handmade picks']);
        expect(array_values(app(HomeCollections::class)->options('bn')))->toBe(['ঈদ স্পেশাল', 'হাতে তৈরি বাছাই']);

        $eid = ($this->col)('eid-specials');
        expect(app(HomeCollections::class)->exists($eid))->toBeTrue()->and(app(HomeCollections::class)->exists(strtolower((string) Str::ulid())))->toBeFalse();
        DB::table('collections')->where('public_id', $eid)->update(['is_active' => false]);
        expect(app(HomeCollections::class)->exists($eid))->toBeFalse()->and(array_keys(app(HomeCollections::class)->options('en')))->not->toContain($eid);
    });

    it('leaves a text block with no text out of the layout itself, not only out of the page', function () {
        $id = ($this->section)('text', ($this->en)('Soon empty', '<p>x</p>'));
        ($this->section)('categories', []);
        DB::table('cms_home_section_translations')->where('section_id', DB::table('cms_home_sections')->where('public_id', $id)->value('id'))->update(['body' => '  ']);

        expect(array_map(fn ($s) => $s->type, app(HomeLayout::class)->sections('en')))->toBe(['categories']);
    });

    it('hands the layout over in order, with windows applied and the visitor\'s language', function () {
        ($this->section)('text', ['en' => ['title' => 'First', 'body' => '<p>one</p>'], 'bn' => ['title' => 'প্রথম', 'body' => '<p>এক</p>']]);
        ($this->section)('categories', []);
        ($this->section)('popular', [], ['active' => false]);

        $en = app(HomeLayout::class)->sections('en');
        $bn = app(HomeLayout::class)->sections('bn');
        expect(array_map(fn ($s) => $s->type, $en))->toBe(['text', 'categories'])->and($en[0]->title)->toBe('First')->and($bn[0]->title)->toBe('প্রথম')->and($bn[0]->body)->toContain('এক')
            ->and($en[1]->title)->toBeNull()->and($en[1]->body)->toBeNull();
    });
});
