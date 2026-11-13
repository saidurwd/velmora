<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Cms\Actions\SaveMenuItem;
use App\Modules\Cms\DTOs\MenuLink;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\StorefrontCache;
use Illuminate\Cache\FileStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/*
 * The tests normally keep the cache in memory, where an object comes back as the same object. A real shop keeps it in a file, the database or Redis, where it is written
 * out and read back, and only classes on the allow-list (config cache.serializable_classes) come back as objects. This test uses a real file cache, so a cached value
 * whose class is missing from that list fails here instead of on the live site (it did: the storefront menus crashed every page after the first once a menu existed).
 */

beforeEach(function () {
    $this->seed(LanguageSeeder::class);
    $this->cacheDir = sys_get_temp_dir().'/velmora-cache-'.bin2hex(random_bytes(4));
    config(['cache.default' => 'file', 'cache.stores.file.path' => $this->cacheDir, 'cache.stores.file.lock_path' => $this->cacheDir]);
    Cache::setDefaultDriver('file');
    app()->forgetInstance('cache.store');   // the store the container hands out was chosen at boot (the in-memory one)
    app()->forgetInstance(StorefrontCache::class);
    Cache::store('file')->flush();
    expect(app(Repository::class)->getStore())->toBeInstanceOf(FileStore::class);
});

afterEach(function () {
    Cache::store('file')->flush();
    @rmdir($this->cacheDir);
});

it('opens storefront pages again and again from a real cache when the header and footer menus exist', function () {
    $actor = Actor::system('t');
    app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', 'Women', 'women'), new TranslationInput('bn', 'নারী')]);
    $save = app(SaveMenuItem::class);
    foreach (['header', 'footer'] as $menu) {
        $group = $save->handle($actor, null, $menu, 'group', null, null, true, true, ['en' => ['label' => 'Shop'], 'bn' => ['label' => 'কেনাকাটা']]);
        $save->handle($actor, null, $menu, 'url', $group, null, true, true, ['en' => ['label' => 'Women', 'url' => '/en/c/women'], 'bn' => ['label' => 'নারী', 'url' => '/bn/shop']]);
        $save->handle($actor, null, $menu, 'url', null, null, true, true, ['en' => ['label' => 'All products', 'url' => '/en/shop'], 'bn' => ['label' => 'সব পণ্য', 'url' => '/bn/shop']]);
    }

    foreach (['/en', '/en', '/en/shop', '/en/shop', '/en/c/women', '/en/c/women', '/bn', '/bn', '/en/cart'] as $url) {
        $this->get($url)->assertOk()->assertSee($url[1] === 'b' ? 'সব পণ্য' : 'All products');
    }
});

it('lists every class the storefront cache stores, so each one comes back from a real cache as itself', function () {
    foreach ((array) config('cache.serializable_classes') as $class) {
        expect(class_exists($class))->toBeTrue($class);
    }
    $link = new MenuLink('Shop', null, [new MenuLink('Women', '/en/c/women')]);

    $back = app(StorefrontCache::class)->remember('probe-menu', fn () => [$link]);
    $again = app(StorefrontCache::class)->remember('probe-menu', fn () => throw new RuntimeException('must come from the cache'));

    expect($back[0])->toBeInstanceOf(MenuLink::class)->and($again[0])->toBeInstanceOf(MenuLink::class)
        ->and($again[0]->isGroup())->toBeTrue()->and($again[0]->children[0]->url)->toBe('/en/c/women');
});
