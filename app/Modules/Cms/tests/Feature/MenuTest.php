<?php

declare(strict_types=1);

use App\Modules\Cms\Actions\SaveMenuItem;
use App\Modules\Cms\Actions\SavePage;
use App\Modules\Cms\Actions\TransitionPage;
use App\Modules\Cms\Contracts\MenuReader;
use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Cms\Models\Page;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\StorefrontCache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Phase 10, slice 3c-1: the header and footer menus.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->actor = Actor::system('t');
    $this->staff = catalogStaff(['cms.view', 'cms.edit', 'cms.publish']);

    $this->page = function (string $title, bool $publish = true): Page {
        $page = app(SavePage::class)->handle($this->actor, null, ['en' => ['title' => $title, 'slug' => strtolower($title), 'body' => '<p>x</p>'], 'bn' => ['title' => $title.' বাংলা', 'body' => '<p>ব</p>']]);
        $publish && app(TransitionPage::class)->publish($this->actor, $page, 1);

        return $page->fresh();
    };
    /** Makes an item through the Action. $texts: en and bn label (and url). Returns its public id. */
    $this->item = fn (string $menu, string $kind, string $en, ?string $bn = null, array $o = []) => app(SaveMenuItem::class)->handle(
        $this->actor, $o['id'] ?? null, $menu, $kind, $o['parent'] ?? null, $o['page'] ?? null, $o['active'] ?? true, true,
        ['en' => ['label' => $en, 'url' => $o['en_url'] ?? null], 'bn' => ['label' => $bn ?? '', 'url' => $o['bn_url'] ?? null]],
    );
    $this->link = fn (string $en, string $path) => ($this->item)('header', 'url', $en, $en.' বাংলা', ['en_url' => $path, 'bn_url' => str_replace('/en/', '/bn/', $path)]);
    $this->read = fn (string $menu, string $locale = 'en') => app(MenuReader::class)->items($menu, $locale);
    $this->labels = fn (string $menu, string $locale = 'en') => array_map(fn ($l) => $l->label, ($this->read)($menu, $locale));
});

describe('what visitors see', function () {
    it('changes nothing while no menu is set up', function () {
        ($this->page)('About');
        $html = $this->get('/en/help')->assertOk()->getContent();

        expect($html)->not->toContain('aria-label="Main menu"')->and($html)->toContain('/en/pages/about');   // the footer still lists the published pages
        expect(($this->read)('header'))->toBe([])->and(($this->read)('footer'))->toBe([]);
    });

    it('shows active links in order, in the visitor\'s language, and never a hidden one', function () {
        ($this->link)('Shop', '/en/shop');
        ($this->link)('Offers', '/en/offers');
        ($this->item)('header', 'url', 'Secret', 'গোপন', ['en_url' => '/en/secret', 'active' => false]);

        expect(($this->labels)('header'))->toBe(['Shop', 'Offers'])->and(($this->labels)('header', 'bn'))->toBe(['Shop বাংলা', 'Offers বাংলা']);
        $this->get('/en/help')->assertOk()->assertSee('aria-label="Main menu"', false)->assertSee('href="'.url('/en/shop').'"', false)->assertDontSee('Secret');
        $this->get('/bn/help')->assertOk()->assertSee('প্রধান মেনু')->assertSee('href="'.url('/bn/shop').'"', false);
    });

    it('follows a page: its address in each language, and gone while the page is not published', function () {
        $about = ($this->page)('About');
        ($this->item)('header', 'page', 'About us', 'আমাদের কথা', ['page' => $about->public_id]);

        $bnSlug = DB::table('cms_page_translations')->where('page_id', $about->id)->where('locale', 'bn')->value('slug');
        expect(($this->read)('header')[0]->url)->toBe(url('/en/pages/about'))->and(($this->read)('header', 'bn')[0]->url)->toBe(url('/bn/pages/'.rawurlencode((string) $bnSlug)));

        app(TransitionPage::class)->unpublish($this->actor, $about);
        app(StorefrontCache::class)->bump();
        expect(($this->read)('header'))->toBe([]);
        $this->get('/en/help')->assertOk()->assertDontSee('About us');

        app(TransitionPage::class)->publish($this->actor, $about->fresh(), 1);
        expect(($this->labels)('header'))->toBe(['About us']);

        $about->delete();   // a deleted page takes its links with it
        expect(($this->read)('header'))->toBe([]);
    });

    it('uses the English address for a language that has none', function () {
        ($this->item)('header', 'url', 'Shop', 'দোকান', ['en_url' => '/en/shop']);

        ($this->item)('header', 'url', 'Offers only in English', null, ['en_url' => '/en/offers']);

        expect(($this->read)('header', 'bn')[0]->label)->toBe('দোকান')->and(($this->read)('header', 'bn')[0]->url)->toBe(url('/en/shop'))
            ->and(($this->labels)('header', 'bn'))->toBe(['দোকান', 'Offers only in English']);   // a language without a text shows the English one
    });

    it('shows a heading with its visible links, and nothing for a heading with no visible links or a hidden heading', function () {
        $group = ($this->item)('header', 'group', 'Help', 'সহায়তা');
        ($this->item)('header', 'url', 'Delivery', 'ডেলিভারি', ['parent' => $group, 'en_url' => '/en/pages/delivery']);
        ($this->item)('header', 'url', 'Hidden child', null, ['parent' => $group, 'en_url' => '/en/x', 'active' => false]);
        $empty = ($this->item)('header', 'group', 'Empty heading', null);
        ($this->item)('header', 'url', 'Gone', null, ['parent' => $empty, 'en_url' => '/en/gone', 'active' => false]);
        $off = ($this->item)('header', 'group', 'Off heading', null, ['active' => false]);
        ($this->item)('header', 'url', 'Under off', null, ['parent' => $off, 'en_url' => '/en/under']);

        $tree = ($this->read)('header');
        expect($tree)->toHaveCount(1)->and($tree[0]->label)->toBe('Help')->and($tree[0]->isGroup())->toBeTrue()->and(array_map(fn ($c) => $c->label, $tree[0]->children))->toBe(['Delivery']);
        $this->get('/en/help')->assertOk()->assertSee('<details', false)->assertSee('Delivery')->assertDontSee('Empty heading')->assertDontSee('Under off');
    });

    it('lists the footer menu in columns instead of the automatic list, and falls back to that list when nothing is shown', function () {
        ($this->page)('About');
        $col = ($this->item)('footer', 'group', 'Company', 'কোম্পানি');
        ($this->item)('footer', 'url', 'Careers', 'ক্যারিয়ার', ['parent' => $col, 'en_url' => '/en/careers']);
        ($this->item)('footer', 'url', 'Contact', 'যোগাযোগ', ['en_url' => '/en/contact']);

        $html = $this->get('/en/help')->assertOk()->getContent();
        expect($html)->toContain('aria-label="Footer menu"')->toContain('Company')->toContain('Careers')->toContain('Contact')->not->toContain('/en/pages/about');

        DB::table('cms_menu_items')->where('menu', 'footer')->update(['is_active' => false]);
        app(StorefrontCache::class)->bump();
        $this->get('/en/help')->assertOk()->assertDontSee('aria-label="Footer menu"', false)->assertSee('/en/pages/about');
    });

    it('never renders an address that is not safe, even one that got into the database', function () {
        $id = ($this->link)('Fine', '/en/fine');
        foreach (['javascript:alert(1)', '//evil.example/x', 'http://plain.example', '/en/a b'] as $i => $bad) {
            $row = DB::table('cms_menu_items')->insertGetId(['public_id' => str_pad("BAD{$i}", 26, 'X'), 'menu' => 'header', 'kind' => 'url', 'sort_order' => 50 + $i, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('cms_menu_item_translations')->insert(['item_id' => $row, 'locale' => 'en', 'label' => "Bad {$i}", 'url' => $bad]);
        }

        expect(($this->labels)('header'))->toBe(['Fine'])->and($id)->not->toBe('');
    });
});

describe('saving an item', function () {
    it('needs the English text, a page for a page link, and only safe addresses', function () {
        $save = fn (array $o = []) => fn () => ($this->item)('header', $o['kind'] ?? 'url', $o['en'] ?? 'Shop', $o['bn'] ?? null, $o + ['en_url' => '/en/shop']);

        expect($save(['en' => '']))->toThrow(CmsException::class, 'menu_default_required')
            ->and($save(['kind' => 'page']))->toThrow(CmsException::class, 'menu_page_missing')
            ->and($save(['kind' => 'page', 'page' => str_repeat('0', 26)]))->toThrow(CmsException::class, 'menu_page_missing')
            ->and($save(['en_url' => 'javascript:alert(1)']))->toThrow(CmsException::class, 'unsafe_link')
            ->and($save(['en_url' => '//evil.example']))->toThrow(CmsException::class, 'unsafe_link')
            ->and($save(['en_url' => 'http://plain.example']))->toThrow(CmsException::class, 'unsafe_link')
            ->and($save(['en_url' => '']))->toThrow(CmsException::class, 'unsafe_link')
            ->and($save(['bn' => 'দোকান', 'bn_url' => 'javascript:alert(1)']))->toThrow(CmsException::class, 'unsafe_link')
            ->and(fn () => app(SaveMenuItem::class)->handle($this->actor, null, 'sidebar', 'url', null, null, true, true, ['en' => ['label' => 'x', 'url' => '/en/x']]))->toThrow(CmsException::class, 'menu_invalid')
            ->and(fn () => app(SaveMenuItem::class)->handle($this->actor, null, 'header', 'carousel', null, null, true, true, ['en' => ['label' => 'x']]))->toThrow(CmsException::class, 'menu_invalid')
            ->and(DB::table('cms_menu_items')->count())->toBe(0);
    });

    it('keeps headings one level deep, in their own menu, and holding links only', function () {
        $group = ($this->item)('header', 'group', 'Help');
        $footerGroup = ($this->item)('footer', 'group', 'Company');
        $child = ($this->item)('header', 'url', 'Delivery', null, ['parent' => $group, 'en_url' => '/en/d']);
        $topLink = ($this->item)('header', 'url', 'Top link', null, ['en_url' => '/en/t']);

        expect(fn () => ($this->item)('header', 'group', 'Nested heading', null, ['parent' => $group]))->toThrow(CmsException::class, 'menu_parent_invalid')            // heading under a heading
            ->and(fn () => ($this->item)('header', 'url', 'Wrong menu', null, ['parent' => $footerGroup, 'en_url' => '/en/x']))->toThrow(CmsException::class, 'menu_parent_invalid')
            ->and(fn () => ($this->item)('header', 'url', 'Under a link', null, ['parent' => $child, 'en_url' => '/en/x']))->toThrow(CmsException::class, 'menu_parent_invalid')   // only headings hold links
            ->and(fn () => ($this->item)('header', 'url', 'Under a top link', null, ['parent' => $topLink, 'en_url' => '/en/x']))->toThrow(CmsException::class, 'menu_parent_invalid')
            ->and(fn () => ($this->item)('header', 'url', 'Nowhere', null, ['parent' => str_repeat('0', 26), 'en_url' => '/en/x']))->toThrow(CmsException::class, 'menu_parent_invalid')
            ->and(fn () => ($this->item)('header', 'url', 'Now a link', null, ['id' => $group, 'en_url' => '/en/x']))->toThrow(CmsException::class, 'menu_has_children')              // a heading with links stays a heading
            ->and(fn () => ($this->item)('header', 'group', 'Itself', null, ['id' => $group, 'parent' => $group]))->toThrow(CmsException::class, 'menu_parent_invalid');
    });

    it('lets the database refuse the shapes the Action would', function () {
        $row = fn (array $o) => DB::table('cms_menu_items')->insert($o + ['public_id' => strtoupper(substr(md5((string) microtime(true).random_int(0, 9999)), 0, 26)), 'menu' => 'header', 'kind' => 'url', 'sort_order' => 1, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
        $pageId = ($this->page)('About')->id;
        $group = DB::table('cms_menu_items')->insertGetId(['public_id' => 'GROUP00000000000000000000', 'menu' => 'header', 'kind' => 'group', 'sort_order' => 1, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => $row(['menu' => 'sidebar']))->toThrow(QueryException::class)
            ->and(fn () => $row(['kind' => 'carousel']))->toThrow(QueryException::class)
            ->and(fn () => $row(['kind' => 'page']))->toThrow(QueryException::class)                       // a page link needs a page
            ->and(fn () => $row(['kind' => 'url', 'page_id' => $pageId]))->toThrow(QueryException::class)  // and only a page link has one
            ->and(fn () => $row(['kind' => 'group', 'parent_id' => $group]))->toThrow(QueryException::class)           // a heading under a heading
            ->and(fn () => $row(['kind' => 'url', 'parent_id' => 999999]))->toThrow(QueryException::class)             // no such parent
            ->and(fn () => $row(['kind' => 'url', 'menu' => 'footer', 'parent_id' => $group]))->toThrow(QueryException::class)   // a parent in another menu
            ->and(fn () => DB::table('cms_menu_items')->where('id', $group)->update(['parent_id' => $group]))->toThrow(QueryException::class);
        $link = DB::table('cms_menu_items')->insertGetId(['public_id' => 'LINK000000000000000000000', 'menu' => 'header', 'kind' => 'url', 'parent_id' => $group, 'sort_order' => 1, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
        expect(fn () => DB::table('cms_menu_items')->where('id', $link)->update(['parent_id' => $link]))->toThrow(QueryException::class)   // a link under itself
            ->and(fn () => $row(['kind' => 'url', 'parent_id' => $link]))->toThrow(QueryException::class);                                 // a link under a link
    });

    it('puts a new item last among its neighbours, hidden unless a publisher shows it', function () {
        $group = ($this->item)('header', 'group', 'Help');
        $a = ($this->item)('header', 'url', 'A', null, ['en_url' => '/en/a']);
        $b = ($this->item)('header', 'url', 'B', null, ['en_url' => '/en/b']);
        $c = ($this->item)('header', 'url', 'C', null, ['parent' => $group, 'en_url' => '/en/c']);
        $d = ($this->item)('header', 'url', 'D', null, ['parent' => $group, 'en_url' => '/en/d']);

        $orders = fn () => DB::table('cms_menu_items')->whereIn('public_id', [$group, $a, $b, $c, $d])->orderBy('id')->pluck('sort_order')->map(fn ($v) => (int) $v)->all();
        expect($orders())->toBe([1, 2, 3, 1, 2]);

        $new = app(SaveMenuItem::class)->handle($this->actor, null, 'header', 'url', null, null, true, false, ['en' => ['label' => 'Hidden new', 'url' => '/en/n']]);   // may not publish
        expect((bool) DB::table('cms_menu_items')->where('public_id', $new)->value('is_active'))->toBeFalse();
        app(SaveMenuItem::class)->handle($this->actor, $new, 'header', 'url', null, null, true, false, ['en' => ['label' => 'Hidden new', 'url' => '/en/n']]);    // still may not
        expect((bool) DB::table('cms_menu_items')->where('public_id', $new)->value('is_active'))->toBeFalse();
        app(SaveMenuItem::class)->handle($this->actor, $new, 'header', 'url', null, null, true, true, ['en' => ['label' => 'Hidden new', 'url' => '/en/n']]);     // a publisher shows it
        expect((bool) DB::table('cms_menu_items')->where('public_id', $new)->value('is_active'))->toBeTrue();
        app(SaveMenuItem::class)->handle($this->actor, $new, 'header', 'url', null, null, null, true, ['en' => ['label' => 'Hidden new', 'url' => '/en/n']]);      // not mentioned: unchanged
        expect((bool) DB::table('cms_menu_items')->where('public_id', $new)->value('is_active'))->toBeTrue();
    });

    it('moves an item to the end of its new place when its heading changes, and keeps it in its menu', function () {
        $group = ($this->item)('header', 'group', 'Help');
        ($this->item)('header', 'url', 'Z', null, ['en_url' => '/en/z']);
        $a = ($this->item)('header', 'url', 'A', null, ['en_url' => '/en/a']);     // third at the top: order 3
        $c = ($this->item)('header', 'url', 'C', null, ['parent' => $group, 'en_url' => '/en/c']);

        ($this->item)('header', 'url', 'A', null, ['id' => $a, 'parent' => $group, 'en_url' => '/en/a']);
        expect((int) DB::table('cms_menu_items')->where('public_id', $a)->value('sort_order'))->toBe(2)->and((int) DB::table('cms_menu_items')->where('public_id', $c)->value('sort_order'))->toBe(1);

        app(SaveMenuItem::class)->handle($this->actor, $a, 'footer', 'url', $group, null, null, true, ['en' => ['label' => 'A', 'url' => '/en/a']]);   // asked to move menu: it stays
        expect(DB::table('cms_menu_items')->where('public_id', $a)->value('menu'))->toBe('header');
    });

    it('shows a saved item to visitors at once, without waiting for the cache', function () {
        ($this->link)('Shop', '/en/shop');
        $this->get('/en/help')->assertOk()->assertSee('Shop')->assertDontSee('Fresh offers');   // the menu is now cached

        ($this->link)('Fresh offers', '/en/fresh');
        $this->get('/en/help')->assertOk()->assertSee('Fresh offers');
    });

    it('keeps the label of each language and drops a language that was cleared', function () {
        $id = ($this->item)('header', 'url', 'Shop', 'দোকান', ['en_url' => '/en/shop']);
        ($this->item)('header', 'url', 'Shop', '', ['id' => $id, 'en_url' => '/en/shop']);

        expect(DB::table('cms_menu_item_translations')->where('item_id', DB::table('cms_menu_items')->where('public_id', $id)->value('id'))->pluck('locale')->all())->toBe(['en']);
    });
});

describe('ordering and removing', function () {
    beforeEach(function () {
        $this->move = fn (string $id, string $dir) => $this->actingAs($this->staff, 'admin')->post("/admin/cms/menus/{$id}/move", ['direction' => $dir]);
        $this->order = fn (string $menu = 'header', ?string $parent = null) => DB::table('cms_menu_items as i')->join('cms_menu_item_translations as t', fn ($j) => $j->on('t.item_id', '=', 'i.id')->where('t.locale', 'en'))
            ->where('i.menu', $menu)->where('i.parent_id', $parent === null ? null : DB::table('cms_menu_items')->where('public_id', $parent)->value('id'))->orderBy('i.sort_order')->orderBy('i.id')->pluck('t.label')->all();
    });

    it('swaps an item with its neighbour, ignores a move off the end, and tidies a duplicated order', function () {
        $a = ($this->link)('A', '/en/a');
        $b = ($this->link)('B', '/en/b');
        $c = ($this->link)('C', '/en/c');

        ($this->move)($c, 'up')->assertRedirect();
        expect(($this->order)())->toBe(['A', 'C', 'B']);
        ($this->move)($a, 'up')->assertRedirect();                          // already first
        ($this->move)($b, 'down')->assertRedirect();                        // already last
        expect(($this->order)())->toBe(['A', 'C', 'B']);

        DB::table('cms_menu_items')->update(['sort_order' => 5]);           // a messy order: all equal
        ($this->move)($b, 'up')->assertRedirect();
        expect(DB::table('cms_menu_items')->orderBy('sort_order')->pluck('sort_order')->map(fn ($v) => (int) $v)->all())->toBe([1, 2, 3]);
    });

    it('moves among neighbours only: the same heading, the same menu', function () {
        $group = ($this->item)('header', 'group', 'Help');
        $top = ($this->link)('Top', '/en/top');
        $x = ($this->item)('header', 'url', 'X', null, ['parent' => $group, 'en_url' => '/en/x']);
        $y = ($this->item)('header', 'url', 'Y', null, ['parent' => $group, 'en_url' => '/en/y']);
        $footerLink = ($this->item)('footer', 'url', 'F', null, ['en_url' => '/en/f']);

        ($this->move)($y, 'up');
        expect(($this->order)('header', $group))->toBe(['Y', 'X'])->and(($this->order)('header'))->toBe(['Help', 'Top'])->and(($this->order)('footer'))->toBe(['F']);

        ($this->move)($top, 'up');
        expect(($this->order)('header'))->toBe(['Top', 'Help']);
        ($this->move)($footerLink, 'down');
        expect(($this->order)('footer'))->toBe(['F']);
    });

    it('refreshes what visitors see at once, and refuses a bad direction or an unknown item', function () {
        $a = ($this->link)('A', '/en/a');
        ($this->link)('B', '/en/b');
        $this->get('/en/help')->assertOk();   // fills the cache with A, B

        ($this->move)($a, 'down');
        expect(($this->labels)('header'))->toBe(['B', 'A']);
        $html = $this->get('/en/help')->getContent();
        expect(strpos($html, '>B<'))->toBeLessThan(strpos($html, '>A<'));

        ($this->move)($a, 'sideways')->assertSessionHasErrors('direction');
        ($this->move)(str_repeat('0', 26), 'up')->assertNotFound();
    });

    it('removes a heading together with its links, and audits it', function () {
        $group = ($this->item)('header', 'group', 'Help');
        ($this->item)('header', 'url', 'Zebra link', null, ['parent' => $group, 'en_url' => '/en/x']);
        $keep = ($this->link)('Keep', '/en/keep');
        $this->get('/en/help')->assertOk()->assertSee('Keep')->assertSee('Zebra link');

        $this->actingAs($this->staff, 'admin')->delete("/admin/cms/menus/{$group}")->assertRedirect('/admin/cms/menus');
        $this->get('/en/help')->assertOk()->assertSee('Keep')->assertDontSee('Zebra link');   // gone from the page at once

        expect(DB::table('cms_menu_items')->count())->toBe(1)->and(DB::table('cms_menu_item_translations')->count())->toBe(2)   // Keep's English and Bangla
            ->and(DB::table('audit_events')->where('action', 'cms.menu_item_deleted')->count())->toBe(1)->and(($this->labels)('header'))->toBe(['Keep']);
        $this->actingAs($this->staff, 'admin')->delete("/admin/cms/menus/{$group}")->assertNotFound();
        expect($keep)->not->toBe('');
    });
});

describe('the staff screens', function () {
    it('lists both menus and saves an item through the form, hidden for an editor who may not publish', function () {
        $editor = catalogStaff(['cms.view', 'cms.edit']);
        $this->actingAs($editor, 'admin')->get('/admin/cms/menus')->assertOk()->assertSee('Header menu')->assertSee('Footer menu')->assertSee('No links yet.');
        $this->actingAs($editor, 'admin')->get('/admin/cms/menus/create?menu=header')->assertOk()->assertSee('The link will start hidden.');

        $this->actingAs($editor, 'admin')->post('/admin/cms/menus', ['menu' => 'header', 'kind' => 'url', 'active_present' => '1', 'active' => '1', 'texts' => ['en' => ['label' => 'Shop', 'url' => '/en/shop'], 'bn' => ['label' => 'দোকান', 'url' => '']]])->assertRedirect('/admin/cms/menus');
        expect((bool) DB::table('cms_menu_items')->value('is_active'))->toBeFalse();

        $id = (string) DB::table('cms_menu_items')->value('public_id');
        $this->actingAs($this->staff, 'admin')->get("/admin/cms/menus/{$id}/edit")->assertOk()->assertSee('Show this link')->assertSee('/en/shop');
        $this->actingAs($this->staff, 'admin')->put("/admin/cms/menus/{$id}", ['menu' => 'header', 'kind' => 'url', 'active_present' => '1', 'active' => '1', 'texts' => ['en' => ['label' => 'Shop', 'url' => '/en/shop']]])->assertRedirect('/admin/cms/menus');
        expect((bool) DB::table('cms_menu_items')->value('is_active'))->toBeTrue();
    });

    it('explains a refused save in the editor\'s language, and keeps what was typed', function () {
        $this->actingAs($this->staff, 'admin')->from('/admin/cms/menus/create')->post('/admin/cms/menus', ['menu' => 'header', 'kind' => 'url', 'texts' => ['en' => ['label' => 'Bad', 'url' => 'javascript:alert(1)']]])
            ->assertRedirect('/admin/cms/menus/create')->assertSessionHasErrors('menu')->assertSessionHasInput('texts.en.label', 'Bad');
        $this->actingAs($this->staff, 'admin')->post('/admin/cms/menus', ['menu' => 'header', 'kind' => 'url', 'texts' => ['en' => ['label' => '']]])->assertSessionHasErrors('texts.en.label');
        $this->actingAs($this->staff, 'admin')->post('/admin/cms/menus', ['menu' => 'sidebar', 'kind' => 'url', 'texts' => ['en' => ['label' => 'x']]])->assertSessionHasErrors('menu');
        expect(DB::table('cms_menu_items')->count())->toBe(0);
    });

    it('gates every screen and action by permission', function () {
        $id = ($this->link)('Shop', '/en/shop');
        $viewer = catalogStaff(['cms.view']);
        $form = ['menu' => 'header', 'kind' => 'url', 'texts' => ['en' => ['label' => 'x', 'url' => '/en/x']]];

        $this->actingAs($viewer, 'admin')->get('/admin/cms/menus')->assertOk();
        $this->actingAs($viewer, 'admin')->get("/admin/cms/menus/{$id}/edit")->assertOk();
        $this->actingAs($viewer, 'admin')->get('/admin/cms/menus/create')->assertForbidden();
        $this->actingAs($viewer, 'admin')->post('/admin/cms/menus', $form)->assertForbidden();
        $this->actingAs($viewer, 'admin')->put("/admin/cms/menus/{$id}", $form)->assertForbidden();
        $this->actingAs($viewer, 'admin')->delete("/admin/cms/menus/{$id}")->assertForbidden();
        $this->actingAs($viewer, 'admin')->post("/admin/cms/menus/{$id}/move", ['direction' => 'up'])->assertForbidden();
        $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/cms/menus')->assertForbidden();
        $this->actingAs(catalogStaff(['cms.publish']), 'admin')->post('/admin/cms/menus', $form)->assertForbidden();   // showing is not editing
        expect(DB::table('cms_menu_items')->count())->toBe(1);

        auth('admin')->logout();
        $this->flushSession();
        $this->get('/admin/cms/menus')->assertRedirect();
    });

    it('reads in Bangla and keeps the two language files in step', function () {
        ($this->link)('Shop', '/en/shop');
        $this->staff->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($this->staff->fresh(), 'admin')->get('/admin/cms/menus')->assertOk()->assertSee('হেডার মেনু')->assertSee('ফুটার মেনু')->assertSee('লুকানো');
        $this->actingAs($this->staff->fresh(), 'admin')->get('/admin/cms/menus/create')->assertOk()->assertSee('ধরন');

        $keys = function (array $a, string $prefix = '') use (&$keys): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? array_push($out, ...$keys($v, "{$prefix}{$k}.")) : $out[] = "{$prefix}{$k}";
            }

            return $out;
        };
        foreach (['menu', 'gaps'] as $file) {
            $en = $keys(require app_path("Modules/Cms/resources/lang/en/{$file}.php"));
            $bn = $keys(require app_path("Modules/Cms/resources/lang/bn/{$file}.php"));
            expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
        }
    });

    it('costs the same number of queries however many links there are', function () {
        $count = function () {
            app(StorefrontCache::class)->bump();   // a cold cache: the work the menus really cost
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/en/help')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $group = ($this->item)('header', 'group', 'Help');
        ($this->item)('header', 'url', 'One', null, ['parent' => $group, 'en_url' => '/en/one']);
        ($this->item)('footer', 'url', 'F1', null, ['en_url' => '/en/f1']);
        $count();
        $few = $count();
        foreach (range(1, 15) as $i) {
            ($this->item)('header', 'url', "L{$i}", null, ['parent' => $group, 'en_url' => "/en/l{$i}"]);
            ($this->item)('footer', 'url', "F{$i}x", null, ['en_url' => "/en/f{$i}"]);
        }
        $count();

        expect($count())->toBe($few);
    });
});
