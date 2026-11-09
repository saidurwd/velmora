<?php

declare(strict_types=1);

use App\Modules\Cms\Actions\SaveMenuItem;
use App\Modules\Cms\Actions\SavePage;
use App\Modules\Cms\Actions\TransitionPage;
use App\Modules\Cms\Services\TranslationGaps;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Support\Facades\DB;

/*
 * Phase 10, slice 3c-1: the missing-translation dashboard. A field is missing in a language when English has it and that language does not,
 * counted only for what visitors can see now.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->actor = Actor::system('t');
    $this->staff = catalogStaff(['cms.view']);

    $this->page = function (string $title, array $bn, bool $publish = true) {
        $translations = ['en' => ['title' => $title, 'slug' => strtolower($title), 'body' => '<p>English text</p>']];
        $bn === [] || $translations['bn'] = $bn + ['body' => '<p>বাংলা</p>'];
        $page = app(SavePage::class)->handle($this->actor, null, $translations);
        $publish && app(TransitionPage::class)->publish($this->actor, $page, 1);

        return $page->fresh();
    };
    $this->banner = function (array $en, ?array $bn, array $o = []) {
        $id = DB::table('banners')->insertGetId($o + ['public_id' => strtoupper(substr(md5((string) random_int(0, PHP_INT_MAX)), 0, 26)), 'placement' => 'home_hero', 'sort_order' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('banner_translations')->insert(['banner_id' => $id, 'locale' => 'en', 'created_at' => now(), 'updated_at' => now()] + $en);
        $bn === null || DB::table('banner_translations')->insert(['banner_id' => $id, 'locale' => 'bn', 'created_at' => now(), 'updated_at' => now()] + $bn);

        return (string) DB::table('banners')->where('id', $id)->value('public_id');
    };
    $this->faq = function (string $q, ?string $bnAnswer, bool $published = true) {
        $id = DB::table('faq_entries')->insertGetId(['public_id' => strtoupper(substr(md5($q.random_int(0, PHP_INT_MAX)), 0, 26)), 'category' => 'orders', 'sort_order' => 0, 'is_published' => $published, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('faq_entry_translations')->insert(['faq_entry_id' => $id, 'locale' => 'en', 'question' => $q, 'answer' => 'An answer']);
        $bnAnswer === null || DB::table('faq_entry_translations')->insert(['faq_entry_id' => $id, 'locale' => 'bn', 'question' => 'প্রশ্ন', 'answer' => $bnAnswer]);

        return (string) DB::table('faq_entries')->where('id', $id)->value('public_id');
    };
    $this->menuItem = fn (string $en, string $bn, bool $active = true) => app(SaveMenuItem::class)->handle($this->actor, null, 'header', 'url', null, null, $active, true, ['en' => ['label' => $en, 'url' => '/en/x'], 'bn' => ['label' => $bn]]);
    $this->gaps = fn () => app(TranslationGaps::class)->forLocale('bn');
});

describe('what counts as missing', function () {
    it('finds the fields English has and Bangla lacks, in pages that are published', function () {
        ($this->page)('Complete', ['title' => 'সম্পূর্ণ']);
        $noBody = ($this->page)('No body', ['title' => 'বডি নেই', 'body' => '']);
        $none = ($this->page)('No bangla', []);
        ($this->page)('Draft only', [], false);   // not visible: not counted
        $taken = ($this->page)('Taken down', []);
        app(TransitionPage::class)->unpublish($this->actor, $taken);   // was published, keeps its text, no longer visible: not counted

        $pages = ($this->gaps)()['pages'];
        $byTitle = collect($pages['rows'])->keyBy('title');

        expect($pages['live'])->toBe(3)->and($pages['missing'])->toBe(2)->and($pages['capped'])->toBeFalse()
            ->and($byTitle['No body']['fields'])->toBe(['body'])->and($byTitle['No body']['public_id'])->toBe($noBody->public_id)
            ->and($byTitle['No bangla']['fields'])->toBe(['title', 'body'])->and($byTitle['No bangla']['public_id'])->toBe($none->public_id)
            ->and($pages['route'])->toBe('admin.cms.pages.edit');
    });

    it('does not count a field that English itself does not have', function () {
        $page = ($this->page)('Same', ['title' => 'একই']);
        DB::table('cms_page_translations')->where('page_id', $page->id)->update(['body' => null]);   // English has no body either

        expect(($this->gaps)()['pages']['missing'])->toBe(0);

        $banner = ($this->banner)(['title' => 'Eid', 'subtitle' => null, 'cta_label' => '', 'alt' => 'Eid banner'], ['title' => 'ঈদ', 'alt' => 'ঈদের ব্যানার']);
        $row = collect(($this->gaps)()['banners']['rows'])->firstWhere('public_id', $banner);
        expect($row)->toBeNull();   // English subtitle and button text are blank, so Bangla needs neither
    });

    it('finds missing banner text, help questions and menu links that are visible, and skips hidden ones', function () {
        $b1 = ($this->banner)(['title' => 'Eid', 'subtitle' => 'Sale', 'cta_label' => 'Shop', 'alt' => 'Eid banner'], ['title' => 'ঈদ', 'alt' => 'ঈদের ব্যানার']);          // misses subtitle, button
        $b2 = ($this->banner)(['title' => 'No bangla', 'alt' => 'x'], null);                                                                                                   // misses title, alt
        ($this->banner)(['title' => 'Off', 'alt' => 'x'], null, ['is_active' => false]);                                                                                         // hidden
        ($this->banner)(['title' => 'Gone', 'alt' => 'x'], null, ['deleted_at' => now()]);                                                                                       // deleted
        ($this->banner)(['title' => 'Done', 'subtitle' => 'S', 'cta_label' => 'C', 'alt' => 'A'], ['title' => 'T', 'subtitle' => 'S', 'cta_label' => 'C', 'alt' => 'A']);

        $f1 = ($this->faq)('Delivery?', '');
        ($this->faq)('Returns?', 'উত্তর');
        ($this->faq)('Hidden?', null, false);

        $m1 = ($this->menuItem)('Shop', '');
        ($this->menuItem)('Offers', 'অফার');
        ($this->menuItem)('Secret', '', false);

        $g = ($this->gaps)();
        expect($g['banners']['live'])->toBe(3)->and($g['banners']['missing'])->toBe(2)
            ->and(collect($g['banners']['rows'])->firstWhere('public_id', $b1)['fields'])->toBe(['subtitle', 'cta_label'])
            ->and(collect($g['banners']['rows'])->firstWhere('public_id', $b2)['fields'])->toBe(['title', 'alt'])
            ->and($g['faq']['live'])->toBe(2)->and($g['faq']['missing'])->toBe(1)->and($g['faq']['rows'][0])->toMatchArray(['public_id' => $f1, 'fields' => ['answer']])
            ->and($g['menu_items']['live'])->toBe(2)->and($g['menu_items']['missing'])->toBe(1)->and($g['menu_items']['rows'][0])->toMatchArray(['public_id' => $m1, 'title' => 'Shop', 'fields' => ['label']]);
    });

    it('shows the first 200 and says there are more, while counting them all', function () {
        foreach (range(1, 205) as $i) {
            ($this->faq)("Question {$i}", null);
        }

        $faq = ($this->gaps)()['faq'];
        expect($faq['rows'])->toHaveCount(200)->and($faq['missing'])->toBe(205)->and($faq['live'])->toBe(205)->and($faq['capped'])->toBeTrue();
        $this->actingAs($this->staff, 'admin')->get('/admin/cms/translations')->assertOk()->assertSee('205 of 205 visible items miss text.')->assertSee('Showing the first 200.');
    });

    it('does not say there are more when there are exactly 200', function () {
        foreach (range(1, 200) as $i) {
            ($this->faq)("Question {$i}", null);
        }

        $faq = ($this->gaps)()['faq'];
        expect($faq['rows'])->toHaveCount(200)->and($faq['missing'])->toBe(200)->and($faq['capped'])->toBeFalse();
    });

    it('costs the same number of queries however many items there are', function () {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->gaps)();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        ($this->faq)('One', null);
        $few = $count();
        foreach (range(1, 30) as $i) {
            ($this->faq)("More {$i}", null);
            ($this->menuItem)("Link {$i}", '');
            ($this->page)("Page {$i}", []);
        }

        expect($count())->toBe($few);
    });
});

describe('the dashboard screen', function () {
    it('shows one block per content type with counts and links to the editors, and is closed without the view right', function () {
        $page = ($this->page)('Delivery', []);
        $this->actingAs($this->staff, 'admin')->get('/admin/cms/translations')->assertOk()
            ->assertSee('Missing translations')->assertSee('Pages')->assertSee('Banners')->assertSee('Help centre questions')->assertSee('Menu links')
            ->assertSee('1 of 1 visible items miss text.')->assertSee('Title, Content')->assertSee('/admin/cms/pages/'.$page->public_id.'/edit', false)->assertSee('Nothing is missing.');

        $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/cms/translations')->assertForbidden();
        auth('admin')->logout();
        $this->flushSession();
        $this->get('/admin/cms/translations')->assertRedirect();
    });

    it('lists only the languages other than English, and reads in Bangla', function () {
        ($this->page)('Delivery', []);
        $this->staff->forceFill(['locale' => 'bn'])->save();

        $html = $this->actingAs($this->staff->fresh(), 'admin')->get('/admin/cms/translations')->assertOk()->assertSee('অনুবাদ বাকি')->assertSee('শিরোনাম, বিষয়বস্তু')->getContent();
        expect(substr_count($html, '<h2 id="g-'))->toBe(1)->and($html)->toContain('id="g-bn"')->not->toContain('id="g-en"');
    });
});
