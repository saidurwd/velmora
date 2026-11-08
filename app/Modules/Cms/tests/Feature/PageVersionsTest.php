<?php

declare(strict_types=1);

use App\Modules\Cms\Actions\PublishDuePages;
use App\Modules\Cms\Actions\TransitionPage;
use App\Modules\Cms\Contracts\PageDirectory;
use App\Modules\Cms\Models\Page;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\FrozenClock;
use App\Modules\Platform\Support\SlugRedirects;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Phase 10, slice 3a: page versions, staff preview and scheduled publish.
 * Every save is an immutable version; the public sees a version only once it is published.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->staff = catalogStaff(['cms.view', 'cms.edit', 'cms.publish']);
    $this->clock = new FrozenClock(CarbonImmutable::parse('2026-12-01 06:00:00', 'UTC'));
    $this->travelTo(CarbonImmutable::parse('2026-12-01 06:00:00', 'UTC'));   // the web controllers read the real clock; keep both on the same moment
    $this->app->instance(Clock::class, $this->clock);

    $this->form = fn (string $title = 'Delivery information', array $extra = []) => array_replace_recursive(['translations' => [
        'en' => ['title' => $title, 'body' => "<p>{$title} body</p>"],
        'bn' => ['title' => 'ডেলিভারি তথ্য', 'body' => '<p>বাংলা বিষয়বস্তু</p>'],
    ]], $extra);
    $this->create = function (string $title = 'Delivery information'): Page {
        $this->actingAs($this->staff, 'admin')->post('/admin/cms/pages', ($this->form)($title))->assertRedirect();

        return Page::query()->latest('id')->firstOrFail();
    };
    $this->edit = fn (Page $page, int $base, string $title, array $extra = []) => $this->actingAs($this->staff, 'admin')->put("/admin/cms/pages/{$page->public_id}", ($this->form)($title, $extra) + ['base_version' => $base]);
    $this->go = fn (Page $page, array $data) => $this->actingAs($this->staff, 'admin')->post("/admin/cms/pages/{$page->public_id}/transition", $data);
    $this->at = function (string $utc): void {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
        $this->clock->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    };
    $this->audits = fn (string $action) => DB::table('audit_events')->where('action', $action)->count();
});

describe('versions', function () {
    it('makes an immutable version on every save and leaves the public page alone', function () {
        $page = ($this->create)();
        expect($page->draft_version_id)->not->toBeNull()
            ->and(DB::table('cms_page_translations')->where('page_id', $page->id)->count())->toBe(0)   // nothing is public yet
            ->and(DB::table('cms_page_version_translations')->where('version_id', $page->draft_version_id)->count())->toBe(2);

        ($this->go)($page, ['action' => 'publish', 'version' => 1])->assertRedirect();
        $this->get('/en/pages/delivery-information')->assertOk()->assertSee('Delivery information body');

        ($this->edit)($page, 1, 'Delivery information', ['translations' => ['en' => ['body' => '<p>Changed text</p>']]])->assertRedirect();
        $page = $page->fresh();

        expect(DB::table('cms_page_versions')->where('page_id', $page->id)->pluck('number')->all())->toBe([1, 2])
            ->and($page->liveVersion->number)->toBe(1)->and($page->draftVersion->number)->toBe(2);
        $this->get('/en/pages/delivery-information')->assertOk()->assertSee('Delivery information body')->assertDontSee('Changed text');

        // the database itself refuses to rewrite or remove history
        expect(fn () => DB::table('cms_page_versions')->where('page_id', $page->id)->update(['number' => 9]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('cms_page_version_translations')->where('version_id', $page->draft_version_id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('cms_page_version_translations')->where('version_id', $page->draft_version_id)->update(['title' => 'x']))->toThrow(QueryException::class);
    });

    it('publishes a newer version, and rolls back to an older one without losing the newer one', function () {
        $page = ($this->create)('First title');
        ($this->go)($page, ['action' => 'publish', 'version' => 1]);
        ($this->edit)($page, 1, 'Second title', ['translations' => ['en' => ['slug' => 'first-title']]]);

        ($this->go)($page, ['action' => 'publish', 'version' => 2])->assertRedirect();
        $this->get('/en/pages/first-title')->assertOk()->assertSee('Second title body');

        ($this->go)($page, ['action' => 'publish', 'version' => 1])->assertRedirect();
        $this->get('/en/pages/first-title')->assertOk()->assertSee('First title body')->assertDontSee('Second title body');
        expect($page->fresh()->liveVersion->number)->toBe(1)->and(DB::table('cms_page_versions')->where('page_id', $page->id)->count())->toBe(2);

        ($this->go)($page, ['action' => 'publish', 'version' => 2]);   // and forward again: version 2 was never lost
        $this->get('/en/pages/first-title')->assertSee('Second title body');
    });

    it('publishing the same version twice changes and audits nothing the second time', function () {
        $page = ($this->create)();
        ($this->go)($page, ['action' => 'publish', 'version' => 1]);
        ($this->go)($page, ['action' => 'publish', 'version' => 1])->assertRedirect();

        expect(($this->audits)('cms.page_published'))->toBe(1);
    });

    it('records a redirect for a changed address only when the new version goes live', function () {
        $page = ($this->create)('Delivery information');
        ($this->go)($page, ['action' => 'publish', 'version' => 1]);
        ($this->edit)($page, 1, 'Delivery information', ['translations' => ['en' => ['slug' => 'shipping-info']]]);

        $this->get('/en/pages/delivery-information')->assertOk();            // saving a draft moves nothing
        $this->get('/en/pages/shipping-info')->assertNotFound();

        ($this->go)($page, ['action' => 'publish', 'version' => 2]);
        $this->get('/en/pages/delivery-information')->assertRedirect(url('/en/pages/shipping-info'))->assertStatus(301);
        $this->get('/en/pages/shipping-info')->assertOk();

        $redirects = app(SlugRedirects::class);
        expect($redirects->resolve('page', 'en', 'delivery-information'))->toBe($page->id);
        ($this->go)($page, ['action' => 'publish', 'version' => 1]);   // back to the old address: its redirect must go, the new one now redirects back
        expect($redirects->resolve('page', 'en', 'delivery-information'))->toBeNull()->and($redirects->resolve('page', 'en', 'shipping-info'))->toBe($page->id);
        $this->get('/en/pages/delivery-information')->assertOk();
    });

    it('removes a language from the public page when the live version no longer has it', function () {
        $page = ($this->create)();
        ($this->go)($page, ['action' => 'publish', 'version' => 1]);
        ($this->edit)($page, 1, 'Delivery information', ['translations' => ['bn' => ['title' => '']]]);
        ($this->go)($page, ['action' => 'publish', 'version' => 2]);

        expect(DB::table('cms_page_translations')->where('page_id', $page->id)->pluck('locale')->all())->toBe(['en']);
    });

    it('turns away a save that started from an old version', function () {
        $page = ($this->create)();
        ($this->edit)($page, 1, 'Edited by the first person')->assertRedirect();

        ($this->edit)($page, 1, 'Edited by the second person')->assertSessionHasErrors('translations');   // also started from version 1
        ($this->edit)($page, 0, 'Edited from nothing')->assertSessionHasErrors('translations');

        expect(DB::table('cms_page_versions')->where('page_id', $page->id)->count())->toBe(2)
            ->and($page->fresh()->draftVersion->title())->toBe('Edited by the first person');
        ($this->edit)($page, 2, 'Third')->assertRedirect();   // starting from the newest works
        expect(DB::table('cms_page_versions')->where('page_id', $page->id)->count())->toBe(3);
    });

    it('requires the version the editor started from', function () {
        $page = ($this->create)();
        $this->actingAs($this->staff, 'admin')->put("/admin/cms/pages/{$page->public_id}", ($this->form)())->assertSessionHasErrors('base_version');
    });

    it('keeps slugs unique between a live page and another page\'s draft', function () {
        $a = ($this->create)('Same name');
        $b = ($this->create)('Same name');   // a's draft already uses the slug
        $slugs = DB::table('cms_page_version_translations')->where('locale', 'en')->orderBy('id')->pluck('slug')->all();

        expect($slugs)->toBe(['same-name', 'same-name-2']);
        ($this->go)($a, ['action' => 'publish', 'version' => 1]);
        $c = ($this->create)('Same name');
        expect($c->draftVersion->translations->firstWhere('locale', 'en')->slug)->toBe('same-name-3');
    });

    it('stops a publish when another page already owns the address, and changes nothing', function () {
        $page = ($this->create)();
        $other = Page::query()->create(['status' => 'published']);
        DB::table('cms_page_translations')->insert(['page_id' => $other->id, 'locale' => 'en', 'title' => 'Other', 'slug' => 'delivery-information', 'created_at' => now(), 'updated_at' => now()]);

        ($this->go)($page, ['action' => 'publish', 'version' => 1])->assertSessionHasErrors('transition');
        expect($page->fresh()->status)->toBe('draft')->and($page->fresh()->live_version_id)->toBeNull()
            ->and(DB::table('cms_page_translations')->where('page_id', $page->id)->count())->toBe(0);   // the Bangla row, written before the clash, was rolled back too
    });

    it('refuses to publish a version without default-language content and an unknown version', function () {
        $this->actingAs($this->staff, 'admin')->post('/admin/cms/pages', ['translations' => ['en' => ['title' => 'Empty']]]);
        $page = Page::firstOrFail();

        ($this->go)($page, ['action' => 'publish', 'version' => 1])->assertSessionHasErrors('transition');
        ($this->go)($page, ['action' => 'publish', 'version' => 7])->assertSessionHasErrors('transition');
        ($this->go)($page, ['action' => 'publish'])->assertSessionHasErrors('version');
    });

    it('refreshes the footer links when a page goes live or comes down', function () {
        $page = ($this->create)('Returns corner');
        $this->get('/en/help')->assertOk()->assertDontSee('Returns corner');

        ($this->go)($page, ['action' => 'publish', 'version' => 1]);
        $this->get('/en/help')->assertOk()->assertSee('Returns corner');

        ($this->go)($page, ['action' => 'unpublish']);
        $this->get('/en/help')->assertOk()->assertDontSee('Returns corner');
    });

    it('can take a page down and bring the same version back', function () {
        $page = ($this->create)();
        ($this->go)($page, ['action' => 'publish', 'version' => 1]);
        ($this->go)($page, ['action' => 'unpublish']);
        $this->get('/en/pages/delivery-information')->assertNotFound();
        expect($page->fresh()->live_version_id)->toBeNull();

        ($this->go)($page, ['action' => 'publish', 'version' => 1]);
        $this->get('/en/pages/delivery-information')->assertOk();
    });
});

describe('preview', function () {
    it('shows any version to staff, never cached or indexed, and in the chosen language', function () {
        $page = ($this->create)();   // a draft: not public

        $this->actingAs($this->staff, 'admin')->get("/admin/cms/pages/{$page->public_id}/versions/1/preview")
            ->assertOk()->assertSee('Delivery information body')->assertSee('Preview of version 1')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->staff, 'admin')->get("/admin/cms/pages/{$page->public_id}/versions/1/preview?locale=bn")
            ->assertOk()->assertSee('বাংলা বিষয়বস্তু')->assertSee('সংস্করণ 1-এর প্রিভিউ', false);
        $this->get('/en/pages/delivery-information')->assertNotFound();
    });

    it('is closed to the public, to customers and to staff without the view right, and knows only versions of its own page', function () {
        $page = ($this->create)();
        $other = ($this->create)('Other page');
        $url = "/admin/cms/pages/{$page->public_id}/versions/1/preview";

        auth('admin')->logout();
        $this->flushSession();
        $this->get($url)->assertRedirect();   // not signed in
        $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get($url)->assertForbidden();
        $this->actingAs($this->staff, 'admin')->get("/admin/cms/pages/{$page->public_id}/versions/2/preview")->assertNotFound();
        $this->actingAs($this->staff, 'admin')->get("/admin/cms/pages/{$other->public_id}/versions/1/preview")->assertOk()->assertSee('Other page body');
    });
});

describe('scheduled publish', function () {
    it('goes live once at the time, not before, and never twice', function () {
        $page = ($this->create)();
        ($this->go)($page, ['action' => 'schedule', 'version' => 1, 'publish_at' => '2026-12-02T12:00'])->assertRedirect();   // 12:00 Dhaka = 06:00 UTC

        $this->get('/en/pages/delivery-information')->assertNotFound();
        expect($page->fresh()->scheduled_version_id)->not->toBeNull()->and($page->fresh()->publish_at->toIso8601String())->toBe('2026-12-02T06:00:00+00:00')
            ->and(app(PublishDuePages::class)->handle())->toBe(0);

        ($this->at)('2026-12-02 05:59:59');
        expect(app(PublishDuePages::class)->handle())->toBe(0);
        $this->get('/en/pages/delivery-information')->assertNotFound();

        ($this->at)('2026-12-02 06:00:00');
        expect(app(PublishDuePages::class)->handle())->toBe(1);
        $this->get('/en/pages/delivery-information')->assertOk();
        expect($page->fresh()->scheduled_version_id)->toBeNull()->and($page->fresh()->publish_at)->toBeNull()->and($page->fresh()->status)->toBe('published');

        expect(app(PublishDuePages::class)->handle())->toBe(0)   // a second worker, or a replay, finds nothing to do
            ->and(($this->audits)('cms.page_published'))->toBe(1);
    });

    it('does not show a scheduled page in the footer list or the sitemap before its time', function () {
        $page = ($this->create)();
        ($this->go)($page, ['action' => 'schedule', 'version' => 1, 'publish_at' => '2026-12-02T12:00']);

        expect(app(PageDirectory::class)->published('en'))->toBe([]);
        ($this->at)('2026-12-02 06:30:00');
        app(PublishDuePages::class)->handle();
        expect(app(PageDirectory::class)->published('en'))->toHaveCount(1);
    });

    it('refreshes the footer links when the scheduler publishes', function () {
        $page = ($this->create)('Scheduled corner');
        ($this->at)('2026-12-02 05:59:00');   // close to go-live, so the cached footer has not simply expired by then
        ($this->go)($page, ['action' => 'schedule', 'version' => 1, 'publish_at' => '2026-12-02T12:00']);
        $this->get('/en/help')->assertOk()->assertDontSee('Scheduled corner');   // fills the footer cache

        ($this->at)('2026-12-02 06:00:30');
        app(PublishDuePages::class)->handle();
        $this->get('/en/help')->assertOk()->assertSee('Scheduled corner');
    });

    it('keeps the old version live until the newer one is due, then swaps', function () {
        $page = ($this->create)('Old words');
        ($this->go)($page, ['action' => 'publish', 'version' => 1]);
        ($this->edit)($page, 1, 'New words', ['translations' => ['en' => ['slug' => 'old-words']]]);
        ($this->go)($page, ['action' => 'schedule', 'version' => 2, 'publish_at' => '2026-12-02T12:00']);

        $this->get('/en/pages/old-words')->assertSee('Old words body');
        ($this->at)('2026-12-02 06:01:00');
        app(PublishDuePages::class)->handle();
        $this->get('/en/pages/old-words')->assertSee('New words body')->assertDontSee('Old words body');
    });

    it('drops the schedule when that same version is published now', function () {
        $page = ($this->create)();
        ($this->go)($page, ['action' => 'schedule', 'version' => 1, 'publish_at' => '2026-12-05T12:00']);
        ($this->go)($page, ['action' => 'publish', 'version' => 1]);

        expect($page->fresh()->scheduled_version_id)->toBeNull()->and($page->fresh()->publish_at)->toBeNull()->and($page->fresh()->status)->toBe('published');
    });

    it('does nothing for a page that is not due, was cancelled or is unknown, even when asked directly', function () {
        $page = ($this->create)();
        $transition = app(TransitionPage::class);
        $system = Actor::system('scheduler');

        expect($transition->publishDue($system, $page->id))->toBeFalse();   // never scheduled
        ($this->go)($page, ['action' => 'schedule', 'version' => 1, 'publish_at' => '2026-12-05T12:00']);
        expect($transition->publishDue($system, $page->id))->toBeFalse()   // scheduled, but not yet
            ->and($page->fresh()->status)->toBe('draft')->and($page->fresh()->scheduled_version_id)->not->toBeNull()
            ->and($transition->publishDue($system, 999999))->toBeFalse();
    });

    it('refuses a time in the past, and can cancel or replace a schedule', function () {
        $page = ($this->create)();
        ($this->go)($page, ['action' => 'schedule', 'version' => 1, 'publish_at' => '2026-11-30T12:00'])->assertSessionHasErrors('transition');
        expect($page->fresh()->scheduled_version_id)->toBeNull();

        ($this->go)($page, ['action' => 'schedule', 'version' => 1, 'publish_at' => '2026-12-05T12:00']);
        ($this->go)($page, ['action' => 'schedule', 'version' => 1, 'publish_at' => '2026-12-03T12:00']);   // replaces
        expect($page->fresh()->publish_at->toIso8601String())->toBe('2026-12-03T06:00:00+00:00');

        ($this->go)($page, ['action' => 'cancel_schedule'])->assertRedirect();
        expect($page->fresh()->scheduled_version_id)->toBeNull()->and($page->fresh()->publish_at)->toBeNull();
        ($this->at)('2026-12-10 00:00:00');
        expect(app(PublishDuePages::class)->handle())->toBe(0);
        $this->get('/en/pages/delivery-information')->assertNotFound();
    });

    it('drops a schedule that can no longer be published, writes an audit line and leaves the page as it was', function () {
        $page = ($this->create)();
        ($this->go)($page, ['action' => 'schedule', 'version' => 1, 'publish_at' => '2026-12-02T12:00']);
        $other = Page::query()->create(['status' => 'published']);   // takes the English address in the meantime; Bangla is written first and must be rolled back
        DB::table('cms_page_translations')->insert(['page_id' => $other->id, 'locale' => 'en', 'title' => 'Other', 'slug' => 'delivery-information', 'created_at' => now(), 'updated_at' => now()]);

        ($this->at)('2026-12-02 07:00:00');
        expect(app(PublishDuePages::class)->handle())->toBe(0);

        $page = $page->fresh();
        expect($page->status)->toBe('draft')->and($page->scheduled_version_id)->toBeNull()->and($page->publish_at)->toBeNull()
            ->and(DB::table('cms_page_translations')->where('page_id', $page->id)->count())->toBe(0)
            ->and(($this->audits)('cms.page_schedule_failed'))->toBe(1)->and(($this->audits)('cms.page_published'))->toBe(0);
        expect(app(PublishDuePages::class)->handle())->toBe(0);   // not retried forever
    });

    it('publishes only what is due, in one pass', function () {
        $due = ($this->create)('Due page');
        $later = ($this->create)('Later page');
        ($this->go)($due, ['action' => 'schedule', 'version' => 1, 'publish_at' => '2026-12-02T12:00']);
        ($this->go)($later, ['action' => 'schedule', 'version' => 1, 'publish_at' => '2026-12-09T12:00']);

        ($this->at)('2026-12-03 00:00:00');
        expect(app(PublishDuePages::class)->handle())->toBe(1)
            ->and($due->fresh()->liveVersion->page_id)->toBe($due->id)
            ->and($later->fresh()->scheduledVersion->page_id)->toBe($later->id)   // each page goes live with its own version
            ->and($due->fresh()->status)->toBe('published')->and($later->fresh()->status)->toBe('draft')->and($later->fresh()->scheduled_version_id)->not->toBeNull();
    });

    it('is on the scheduler every minute', function () {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'cms:publish-due');
        expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue();
    });
});

describe('access and screens', function () {
    it('gates saving, publishing and the version list by permission', function () {
        $page = ($this->create)();
        $viewer = catalogStaff(['cms.view']);

        $this->actingAs($viewer, 'admin')->get("/admin/cms/pages/{$page->public_id}/edit")->assertOk()->assertSee('Versions');
        $this->actingAs($viewer, 'admin')->put("/admin/cms/pages/{$page->public_id}", ($this->form)() + ['base_version' => 1])->assertForbidden();
        $this->actingAs($viewer, 'admin')->post("/admin/cms/pages/{$page->public_id}/transition", ['action' => 'publish', 'version' => 1])->assertForbidden();
        $this->actingAs(catalogStaff(['cms.edit']), 'admin')->post("/admin/cms/pages/{$page->public_id}/transition", ['action' => 'publish', 'version' => 1])->assertForbidden();
        expect($page->fresh()->status)->toBe('draft');
    });

    it('shows version numbers, states and the unpublished-changes notice, in English and Bangla', function () {
        $page = ($this->create)();
        ($this->go)($page, ['action' => 'publish', 'version' => 1]);
        ($this->edit)($page, 1, 'Newer words', ['translations' => ['en' => ['slug' => 'delivery-information']]]);

        $this->actingAs($this->staff, 'admin')->get("/admin/cms/pages/{$page->public_id}/edit")
            ->assertOk()->assertSee('The latest draft (version 2) is not live. The public sees version 1.')->assertSee('Live')->assertSee('Latest draft')
            ->assertSee('name="base_version" value="2"', false);

        $this->staff->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($this->staff->fresh(), 'admin')->get("/admin/cms/pages/{$page->public_id}/edit")
            ->assertOk()->assertSee('সর্বশেষ খসড়া (সংস্করণ 2) এখনও চালু নয়', false)->assertSee('সংস্করণ');
        $this->actingAs($this->staff->fresh(), 'admin')->get('/admin/cms/pages')->assertOk()->assertSee('Newer words');
    });

    it('lists pages with the same number of queries however many there are', function () {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->staff, 'admin')->get('/admin/cms/pages')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        foreach (['One', 'Two'] as $t) {
            ($this->create)($t);
        }
        $count();   // warm-up: first-call caches (permissions, menus) are not what is measured
        $few = $count();
        foreach (range(1, 12) as $i) {
            ($this->create)("Page {$i}");
        }
        $count();

        expect($count())->toBe($few);
    });

    it('keeps English and Bangla admin strings in step', function () {
        $keys = function (array $a, string $prefix = '') use (&$keys): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? array_push($out, ...$keys($v, "{$prefix}{$k}.")) : $out[] = "{$prefix}{$k}";
            }

            return $out;
        };
        $en = $keys(require app_path('Modules/Cms/resources/lang/en/admin.php'));
        $bn = $keys(require app_path('Modules/Cms/resources/lang/bn/admin.php'));
        expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
    });
});

describe('moving existing pages into versions', function () {
    it('turns each old page into version 1, keeping what was live live and what was scheduled scheduled', function () {
        $migration = require app_path('Modules/Cms/Database/migrations/2026_11_23_000003_backfill_cms_page_versions.php');
        $constraint = require app_path('Modules/Cms/Database/migrations/2026_11_23_000004_constrain_cms_page_schedule.php');
        $constraint->down();   // legacy rows may break the new rule; the real order is backfill, then constrain
        $now = '2026-12-01 06:00:00.000000';
        $mk = function (string $status, ?string $publishAt, string $slug, bool $translations = true) use ($now) {
            $id = DB::table('cms_pages')->insertGetId(['public_id' => strtoupper(substr(md5($slug), 0, 26)), 'status' => $status, 'publish_at' => $publishAt, 'created_at' => $now, 'updated_at' => $now]);
            $translations && DB::table('cms_page_translations')->insert(['page_id' => $id, 'locale' => 'en', 'title' => "T {$slug}", 'slug' => $slug, 'body' => '<p>b</p>', 'created_at' => $now, 'updated_at' => $now]);

            return $id;
        };
        $live = $mk('published', null, 'live');
        $past = $mk('published', '2026-11-01 00:00:00.000000', 'past');
        $future = $mk('published', '2026-12-20 00:00:00.000000', 'future');
        $draft = $mk('draft', null, 'draft');
        $empty = $mk('draft', '2026-12-20 00:00:00.000000', 'empty', false);

        $migration->up();
        $migration->up();   // safe to run twice
        $constraint->up();

        $row = fn (int $id) => DB::table('cms_pages')->where('id', $id)->first();
        expect(DB::table('cms_page_versions')->count())->toBe(4)->and(DB::table('cms_page_version_translations')->count())->toBe(4)
            ->and($row($live)->status)->toBe('published')->and($row($live)->live_version_id)->toBe($row($live)->draft_version_id)
            ->and($row($past)->status)->toBe('published')->and($row($past)->live_version_id)->not->toBeNull()->and($row($past)->publish_at)->toBeNull()
            ->and($row($future)->status)->toBe('draft')->and($row($future)->live_version_id)->toBeNull()->and($row($future)->scheduled_version_id)->toBe($row($future)->draft_version_id)->and($row($future)->publish_at)->toBe('2026-12-20 00:00:00.000000')
            ->and($row($draft)->status)->toBe('draft')->and($row($draft)->live_version_id)->toBeNull()->and($row($draft)->scheduled_version_id)->toBeNull()
            ->and($row($empty)->draft_version_id)->toBeNull()->and($row($empty)->publish_at)->toBeNull();
    });
});
