<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Support\AdminMenu;
use Illuminate\Routing\Middleware\ThrottleRequests;

/* The staff sidebar: grouped by area, in a fixed order, each viewer sees only what they may use, nothing is lost. */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware([EnforceSessionPolicy::class, ThrottleRequests::class]);
    $this->menu = app(AdminMenu::class);
    $this->owner = User::factory()->staff()->create();
    $this->owner->assignRole('Super Admin');
});

it('gives every entry a modules registered a proper area, so a new screen is never lost under "More"', function () {
    $unplaced = collect($this->menu->all())->filter(fn ($i) => $this->menu->place($i['route'])[0] === AdminMenu::OTHER && $i['route'] !== 'admin.dashboard')->pluck('route')->all();

    expect(count($this->menu->all()))->toBeGreaterThan(60)->and($unplaced)->toBe([]);
});

it('keeps the areas in their fixed order and every entry exactly once', function () {
    $grouped = $this->menu->groupedFor($this->owner);
    $keys = array_column($grouped['areas'], 'key');
    $shown = collect($grouped['areas'])->flatMap(fn ($a) => array_column($a['items'], 'route'))->merge(array_column($grouped['top'], 'route'));

    expect($keys)->toBe(array_keys(AdminMenu::AREAS))->and(array_column($grouped['top'], 'route'))->toBe(['admin.dashboard'])
        ->and($shown)->toHaveCount(count($this->menu->visibleFor($this->owner)));
});

it('lists the entries of an area in the order the pattern list gives, then the modules\' own order', function () {
    $catalog = collect($this->menu->groupedFor($this->owner)['areas'])->firstWhere('key', 'catalog')['items'];
    $labels = array_column($catalog, 'label');

    expect(array_slice($labels, 0, 2))->toBe(['catalog::admin.menu.products', 'catalog::admin.menu.categories'])
        ->and(array_slice($labels, 2, 3))->toBe(['catalog::admin.menu.brands', 'catalog::admin.menu.tags', 'catalog::admin.menu.collections']);
});

it('shows a viewer only the areas they may use, and no empty area', function () {
    $staff = catalogStaff(['catalog.view', 'dashboard.view']);

    $keys = array_column($this->menu->groupedFor($staff)['areas'], 'key');

    expect($keys)->toBe(['catalog', 'security'])   // security: everyone has "My sessions"
        ->and(array_column($this->menu->groupedFor($staff)['top'], 'route'))->toBe(['admin.dashboard'])->and(array_column($this->menu->groupedFor(null)['areas'], 'key'))->toBe(['security']);   // only "My sessions", which needs no permission (the page itself needs a sign-in)
});

it('draws the sidebar with area headings, opens the area of the current page and marks the current link', function () {
    $html = $this->actingAs($this->owner, 'admin')->get('/admin/catalog/categories')->assertOk()->getContent();

    preg_match_all('/<details class="group" data-nav-area="[a-z]+"\s*(open)?\s*>/', $html, $tags);
    expect(count($tags[0]))->toBe(count(AdminMenu::AREAS))->and(array_filter($tags[1]))->toHaveCount(1)   // eleven areas, only the one you are in is open
        ->and($html)->toContain('>Catalog</span>')->toContain('>Money</span>')->toContain('>Administration</span>');
    preg_match_all('/aria-current="page"/', $html, $current);
    expect(count($current[0]))->toBe(1);
    preg_match('/<details class="group" data-nav-area="catalog"\s*open\s*>(.*?)<\/details>/s', $html, $open);
    expect($open[1])->toContain('aria-current="page"')->toContain('>Categories<');
});

it('reads in Bangla with every area named', function () {
    $html = $this->actingAs($this->owner, 'admin')->withSession(['locale' => 'bn'])->get('/bn/admin/catalog/categories');
    foreach (array_keys(AdminMenu::AREAS) as $key) {
        expect(__('layout.nav.'.$key, [], 'bn'))->not->toBe('layout.nav.'.$key)->and(__('layout.nav.'.$key, [], 'en'))->not->toBe('layout.nav.'.$key);
    }
    expect(__('layout.nav.other', [], 'bn'))->not->toBe('layout.nav.other');
});
