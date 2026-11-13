<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Database\Seeders\BangladeshLifestyleCatalogSeeder;
use App\Modules\Cms\Actions\SaveMenuItem;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Search\Database\Seeders\LifestyleSynonymsSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Everything a Bangladeshi lifestyle shop needs before its first product: categories, variant options and specifications, brands, tags, collections (all in English and
 * Bangla), the storefront header and footer menus, and the spellings the search should understand. Structure only: no products, prices or stock.
 *
 * Safe on a live shop and safe to run again: only what is missing is added; nothing existing is changed or deleted. The menus are made only when that menu is still EMPTY, so a menu
 * the staff built is never touched.
 *
 * Run (after the normal first-time set-up):  php artisan db:seed --class='Database\Seeders\BangladeshLifestyleSeeder'
 */
class BangladeshLifestyleSeeder extends Seeder
{
    /** Header: the areas shown as drop-down groups, in order; the rest of the areas are in the footer. */
    private const HEADER_GROUPS = ['women', 'men', 'kids', 'footwear', 'bags-accessories', 'beauty-personal-care', 'home-living', 'handicrafts-heritage'];

    /** How many sub-categories a header group lists (after "All ..."). */
    private const HEADER_CHILDREN = 7;

    public function run(): void
    {
        $this->call(LanguageSeeder::class);
        $this->call(BangladeshLifestyleCatalogSeeder::class);
        $this->call(LifestyleSynonymsSeeder::class);

        $actor = Actor::system('lifestyle-seeder');
        $this->headerMenu($actor);
        $this->footerMenu($actor);
    }

    private function headerMenu(Actor $actor): void
    {
        if (DB::table('cms_menu_items')->where('menu', 'header')->exists()) {
            $this->command?->warn('The header menu already has items: left as it is.');

            return;
        }
        $names = $this->categoryNames();
        $save = app(SaveMenuItem::class);

        foreach (self::HEADER_GROUPS as $slug) {
            $group = $save->handle($actor, null, 'header', 'group', null, null, true, true, $this->labels($names[$slug]));
            $save->handle($actor, null, 'header', 'url', $group, null, true, true, $this->link($names[$slug], $slug, 'all'));
            foreach (array_slice($names[$slug]['children'], 0, self::HEADER_CHILDREN) as $child) {
                $save->handle($actor, null, 'header', 'url', $group, null, true, true, $this->link($names[$child], $child));
            }
        }
        $save->handle($actor, null, 'header', 'url', null, null, true, true, $this->link($names['gifts-occasions'], 'gifts-occasions'));
        $save->handle($actor, null, 'header', 'url', null, null, true, true, [
            'en' => ['label' => 'New arrivals', 'url' => '/en/shop?sort=newest'],
            'bn' => ['label' => 'নতুন পণ্য', 'url' => '/bn/shop?sort=newest'],
        ]);
        $this->command?->info('Header menu made.');
    }

    private function footerMenu(Actor $actor): void
    {
        if (DB::table('cms_menu_items')->where('menu', 'footer')->exists()) {
            $this->command?->warn('The footer menu already has items: left as it is.');

            return;
        }
        $names = $this->categoryNames();
        $save = app(SaveMenuItem::class);

        $shop = $save->handle($actor, null, 'footer', 'group', null, null, true, true, ['en' => ['label' => 'Shop'], 'bn' => ['label' => 'কেনাকাটা']]);
        $save->handle($actor, null, 'footer', 'url', $shop, null, true, true, ['en' => ['label' => 'All products', 'url' => '/en/shop'], 'bn' => ['label' => 'সব পণ্য', 'url' => '/bn/shop']]);
        foreach (['gifts-occasions', 'fitness-sports', 'books-stationery', 'digital-services'] as $slug) {
            $save->handle($actor, null, 'footer', 'url', $shop, null, true, true, $this->link($names[$slug], $slug));
        }

        $care = $save->handle($actor, null, 'footer', 'group', null, null, true, true, ['en' => ['label' => 'Customer care'], 'bn' => ['label' => 'গ্রাহকসেবা']]);
        foreach ([
            ['Help centre', 'সহায়তা কেন্দ্র', '/%s/help'], ['Contact us', 'যোগাযোগ', '/%s/contact'],
            ['My account', 'আমার অ্যাকাউন্ট', '/%s/account'], ['My orders', 'আমার অর্ডার', '/%s/account/orders'],
        ] as [$en, $bn, $path]) {
            $save->handle($actor, null, 'footer', 'url', $care, null, true, true, ['en' => ['label' => $en, 'url' => sprintf($path, 'en')], 'bn' => ['label' => $bn, 'url' => sprintf($path, 'bn')]]);
        }
        $this->command?->info('Footer menu made.');
    }

    /**
     * Every category's name and slug in each language, and its children's slugs in display order. A menu link must use the slug of ITS language (slugs belong to their language).
     *
     * @return array<string, array{en: array{name: string, slug: string}, bn: array{name: string, slug: string}, children: list<string>}>
     */
    private function categoryNames(): array
    {
        $rows = DB::table('category_translations as t')->join('categories as c', 'c.id', '=', 't.category_id')->select('t.category_id', 't.locale', 't.name', 't.slug', 'c.parent_id', 'c.sort_order')->orderBy('c.sort_order')->orderBy('c.id')->get();
        $byId = [];
        foreach ($rows as $r) {
            $byId[$r->category_id][$r->locale] = ['name' => (string) $r->name, 'slug' => (string) $r->slug];
            $byId[$r->category_id]['parent'] = $r->parent_id;
        }
        $enSlug = fn (int $id): string => $byId[$id]['en']['slug'];
        $out = [];
        foreach ($byId as $id => $t) {
            if (! isset($t['en'])) {
                continue;
            }
            $children = [];
            foreach ($byId as $cid => $c) {
                if (($c['parent'] ?? null) === $id && isset($c['en'])) {
                    $children[] = $enSlug((int) $cid);
                }
            }
            $out[$enSlug((int) $id)] = ['en' => $t['en'], 'bn' => $t['bn'] ?? $t['en'], 'children' => $children];
        }

        return $out;
    }

    /**
     * @param  array{en: array{name: string, slug: string}, bn: array{name: string, slug: string}}  $c
     * @return array<string, array{label: string}>
     */
    private function labels(array $c): array
    {
        return ['en' => ['label' => $c['en']['name']], 'bn' => ['label' => $c['bn']['name']]];
    }

    /**
     * @param  array{en: array{name: string, slug: string}, bn: array{name: string, slug: string}}  $c
     * @return array<string, array{label: string, url: string}>
     */
    private function link(array $c, string $slug, string $kind = 'one'): array
    {
        return [
            'en' => ['label' => $kind === 'all' ? 'All '.$c['en']['name'] : $c['en']['name'], 'url' => '/en/c/'.rawurlencode($c['en']['slug'])],
            'bn' => ['label' => $kind === 'all' ? 'সব '.$c['bn']['name'] : $c['bn']['name'], 'url' => '/bn/c/'.rawurlencode($c['bn']['slug'])],
        ];
    }
}
