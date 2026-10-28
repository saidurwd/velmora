<?php

declare(strict_types=1);

use App\Modules\Cms\Contracts\BannerSource;
use App\Modules\Cms\Contracts\PageDirectory;
use App\Modules\Cms\Database\Seeders\DemoCmsSeeder;
use App\Modules\Cms\Models\Banner;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Support\SafeLink;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\Support\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('media');
    config(['catalog.media.disk' => 'media']);
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
});

function pageForm(array $over = []): array
{
    return array_replace_recursive(['translations' => [
        'en' => ['title' => 'Delivery information', 'body' => '<p>We deliver in 2–4 days.</p><script>alert(1)</script>'],
        'bn' => ['title' => 'ডেলিভারি তথ্য', 'body' => '<p>আমরা ২–৪ দিনে পৌঁছে দিই।</p>'],
    ]], $over);
}

function bannerForm(array $over = []): array
{
    return array_replace_recursive([
        'placement' => 'home_hero', 'link_url' => '/en/pages/delivery', 'is_active' => '1',
        'translations' => ['en' => ['title' => 'Eid sale', 'alt' => 'Eid sale banner'], 'bn' => ['title' => 'ঈদ সেল', 'alt' => 'ঈদ সেলের ব্যানার']],
        'images' => ['en' => UploadedFile::fake()->image('en.jpg', 1800, 600), 'bn' => UploadedFile::fake()->image('bn.jpg', 1800, 600)],
    ], $over);
}

describe('pages', function () {
    it('creates a bilingual page, sanitises the body and gives each language its own slug', function () {
        $staff = catalogStaff(['cms.view', 'cms.edit']);
        $this->actingAs($staff, 'admin')->post('/admin/cms/pages', pageForm())->assertRedirect();

        $page = Page::firstOrFail();
        expect($page->translations)->toHaveCount(2)
            ->and($page->translations->firstWhere('locale', 'en')->slug)->toBe('delivery-information')
            ->and($page->translations->firstWhere('locale', 'bn')->slug)->toBe('ডেলিভারি-তথ্য')
            ->and($page->translations->firstWhere('locale', 'en')->body)->not->toContain('<script>')->toContain('2–4 days');
    });

    it('requires the English title and keeps slugs unique per language', function () {
        $staff = catalogStaff(['cms.edit']);

        $this->actingAs($staff, 'admin')->post('/admin/cms/pages', pageForm(['translations' => ['en' => ['title' => '']]]))->assertSessionHasErrors('translations.en.title');

        $this->actingAs($staff, 'admin')->post('/admin/cms/pages', pageForm());
        $this->actingAs($staff, 'admin')->post('/admin/cms/pages', pageForm());
        expect(DB::table('cms_page_translations')->where('locale', 'en')->pluck('slug')->sort()->values()->all())->toBe(['delivery-information', 'delivery-information-2']);
    });

    it('shows only live pages to the public, in the right language', function () {
        $staff = catalogStaff(['cms.view', 'cms.edit', 'cms.publish']);
        $this->actingAs($staff, 'admin')->post('/admin/cms/pages', pageForm());
        $page = Page::firstOrFail();

        $this->get('/en/pages/delivery-information')->assertNotFound(); // draft

        $this->actingAs($staff, 'admin')->post("/admin/cms/pages/{$page->public_id}/transition", ['action' => 'publish'])->assertRedirect();
        $this->get('/en/pages/delivery-information')->assertOk()->assertSee('Delivery information')->assertDontSee('alert(1)', false);
        $this->get('/bn/pages/'.rawurlencode('ডেলিভারি-তথ্য')) // browsers percent-encode non-ASCII paths
            ->assertOk()->assertSee('ডেলিভারি তথ্য');
        $this->get('/bn/pages/delivery-information')->assertNotFound(); // slugs belong to their language

        $this->actingAs($staff, 'admin')->post("/admin/cms/pages/{$page->public_id}/transition", ['action' => 'unpublish']);
        $this->get('/en/pages/delivery-information')->assertNotFound();
    });

    it('honours a scheduled publish time', function () {
        $clock = new FrozenClock(CarbonImmutable::parse('2026-12-01 06:00:00', 'UTC'));
        $this->app->instance(Clock::class, $clock);
        $staff = catalogStaff(['cms.edit', 'cms.publish']);
        $this->actingAs($staff, 'admin')->post('/admin/cms/pages', pageForm());
        $page = Page::firstOrFail();

        $this->actingAs($staff, 'admin')->post("/admin/cms/pages/{$page->public_id}/transition", ['action' => 'schedule', 'publish_at' => '2026-12-02T12:00'])->assertRedirect();
        $this->get('/en/pages/delivery-information')->assertNotFound();

        $clock->travelTo(CarbonImmutable::parse('2026-12-02 07:00:00', 'UTC')); // 13:00 Dhaka: past the 12:00 Dhaka go-live
        expect($page->fresh()->isLive($clock->now()))->toBeTrue();
        expect(app(PageDirectory::class)->published('en'))->toHaveCount(1);
    });

    it('refuses to publish an empty page', function () {
        $staff = catalogStaff(['cms.edit', 'cms.publish']);
        $this->actingAs($staff, 'admin')->post('/admin/cms/pages', ['translations' => ['en' => ['title' => 'Empty']]]);

        $this->actingAs($staff, 'admin')->post('/admin/cms/pages/'.Page::firstOrFail()->public_id.'/transition', ['action' => 'publish'])->assertSessionHasErrors('transition');
    });

    it('gates the page screens by permission', function () {
        $this->actingAs(catalogStaff(['cms.view']), 'admin')->get('/admin/cms/pages')->assertOk();
        $this->actingAs(catalogStaff(['cms.view']), 'admin')->get('/admin/cms/pages/create')->assertForbidden();
        $this->actingAs(catalogStaff(['cms.edit']), 'admin')->post('/admin/cms/pages/x/transition', ['action' => 'publish'])->assertForbidden();
        $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/cms/banners')->assertForbidden();
    });
});

describe('banners', function () {
    it('stores responsive WebP renditions per language and serves them through the contract', function () {
        $staff = catalogStaff(['cms.view', 'cms.edit']);
        $this->actingAs($staff, 'admin')->post('/admin/cms/banners', bannerForm())->assertRedirect('/admin/cms/banners');

        $banner = Banner::with('translations')->firstOrFail();
        $en = $banner->translations->firstWhere('locale', 'en');
        expect(array_keys($en->renditions))->toEqual([800, 1600]);
        foreach ($en->renditions as $path) {
            Storage::disk('media')->assertExists($path);
            expect(getimagesizefromstring(Storage::disk('media')->get($path))['mime'])->toBe('image/webp');
        }

        $views = app(BannerSource::class)->active('home_hero', 'bn');
        expect($views)->toHaveCount(1)->and($views[0]->title)->toBe('ঈদ সেল')->and($views[0]->alt)->toBe('ঈদ সেলের ব্যানার')->and($views[0]->srcset)->toContain('800w');
    });

    it('falls back to the English image when a language has none', function () {
        $staff = catalogStaff(['cms.edit']);
        $this->actingAs($staff, 'admin')->post('/admin/cms/banners', bannerForm(['translations' => ['bn' => ['title' => null, 'alt' => null]], 'images' => ['bn' => null]]));

        $views = app(BannerSource::class)->active('home_hero', 'bn');
        expect($views)->toHaveCount(1)->and($views[0]->alt)->toBe('Eid sale banner');
    });

    it('requires an English image and alt text', function () {
        $staff = catalogStaff(['cms.edit']);

        $this->actingAs($staff, 'admin')->post('/admin/cms/banners', bannerForm(['images' => ['en' => null]]))->assertSessionHasErrors('banner');
        $this->actingAs($staff, 'admin')->post('/admin/cms/banners', bannerForm(['translations' => ['en' => ['alt' => '']]]))->assertSessionHasErrors('banner');
        expect(Banner::count())->toBe(0);
    });

    it('rejects unsafe links, bad windows and non-images, leaving no files behind', function () {
        $staff = catalogStaff(['cms.edit']);

        $this->actingAs($staff, 'admin')->post('/admin/cms/banners', bannerForm(['link_url' => 'javascript:alert(1)']))->assertSessionHasErrors('banner');
        $this->actingAs($staff, 'admin')->post('/admin/cms/banners', bannerForm(['starts_local' => '2026-12-05T10:00', 'ends_local' => '2026-12-01T10:00']))->assertSessionHasErrors('banner');
        $this->actingAs($staff, 'admin')->post('/admin/cms/banners', bannerForm(['images' => ['en' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;')]]))->assertSessionHasErrors('banner');
        $this->actingAs($staff, 'admin')->post('/admin/cms/banners', bannerForm(['images' => ['en' => UploadedFile::fake()->image('tiny.jpg', 300, 100)]]))->assertSessionHasErrors('banner');

        expect(Banner::count())->toBe(0)->and(Storage::disk('media')->allFiles('banners'))->toBe([]);
    });

    it('shows a banner only inside its window and only while active', function () {
        $clock = new FrozenClock(CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'));
        $this->app->instance(Clock::class, $clock);
        $staff = catalogStaff(['cms.edit']);
        $this->actingAs($staff, 'admin')->post('/admin/cms/banners', bannerForm(['starts_local' => '2026-12-02T06:00', 'ends_local' => '2026-12-03T06:00'])); // Dhaka time

        expect(app(BannerSource::class)->active('home_hero', 'en'))->toBe([]);
        $clock->travelTo(CarbonImmutable::parse('2026-12-02 12:00:00', 'UTC'));
        expect(app(BannerSource::class)->active('home_hero', 'en'))->toHaveCount(1);
        $clock->travelTo(CarbonImmutable::parse('2026-12-03 00:00:00', 'UTC')); // exactly 06:00 Dhaka: ended
        expect(app(BannerSource::class)->active('home_hero', 'en'))->toBe([]);

        Banner::query()->update(['is_active' => false, 'starts_at' => null, 'ends_at' => null]);
        expect(app(BannerSource::class)->active('home_hero', 'en'))->toBe([]);
    });

    it('replaces an image on edit and removes the old files', function () {
        $staff = catalogStaff(['cms.edit']);
        $this->actingAs($staff, 'admin')->post('/admin/cms/banners', bannerForm());
        $banner = Banner::firstOrFail();
        $old = $banner->translations->firstWhere('locale', 'en')->renditions;

        $this->actingAs($staff, 'admin')->put("/admin/cms/banners/{$banner->public_id}", bannerForm(['images' => ['en' => UploadedFile::fake()->image('new.jpg', 1800, 600), 'bn' => null]]))->assertRedirect();

        foreach ($old as $path) {
            Storage::disk('media')->assertMissing($path);
        }
        foreach ($banner->fresh()->translations->firstWhere('locale', 'en')->renditions as $path) {
            Storage::disk('media')->assertExists($path);
        }
    });
});

it('only allows site-relative and https links', function (string $url, bool $safe) {
    expect(SafeLink::isSafe($url))->toBe($safe);
})->with([
    ['/en/pages/delivery', true], ['https://example.com/x?y=1', true], ['http://example.com', false], ['//evil.example', false],
    ['javascript:alert(1)', false], ['data:text/html,x', false], ['/ok path', false], ["/x\ny", false], ['', false], ['/\\evil', false],
]);

it('seeds starter pages and banners, links pages in the footer, and refuses to run in production', function () {
    $this->seed(DemoCmsSeeder::class);
    $before = [Page::count(), Banner::count()];

    expect($before)->toBe([3, 2]);
    $this->seed(DemoCmsSeeder::class);
    expect([Page::count(), Banner::count()])->toBe($before); // re-runnable

    $this->get('/en')->assertOk()->assertSee('/en/pages/faq', false)->assertSee('Delivery information');
    $this->get('/bn')->assertOk()->assertSee('ডেলিভারি তথ্য');

    $this->app['env'] = 'production';
    expect(fn () => (new DemoCmsSeeder)->run())->toThrow(RuntimeException::class);
});
