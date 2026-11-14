<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Web;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Services\Storefront\ListingQuery;
use App\Modules\Cms\Contracts\BannerSource;
use App\Modules\Cms\Contracts\HomeLayout;
use App\Modules\Cms\DTOs\HomeSectionView;
use App\Modules\Platform\Support\Branding;
use App\Modules\Platform\Support\PageSeo;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Platform\Support\StructuredData;
use Illuminate\Contracts\View\View;

/** The storefront home page: banners from the CMS, categories, curated collections, new arrivals, popular products. */
class HomeController
{
    public function __invoke(BannerSource $banners, ListingQuery $listing, PageSeo $seo, Branding $brand, StorefrontCache $cache, HomeLayout $layout): View
    {
        $locale = app()->getLocale();
        $home = url("/{$locale}");
        $seo->describe($brand->tagline() !== '' ? $brand->tagline() : __('catalog::storefront.meta_home', ['name' => $brand->name()]))
            ->jsonLd(StructuredData::organization($brand, $home))
            ->jsonLd(StructuredData::website($brand, $home, url("/{$locale}/search")));

        $data = $cache->remember('home', function () use ($banners, $listing, $locale, $layout): array {
            // The blocks staff set up, when any is visible and has something to show; otherwise the built-in layout below, exactly as before.
            $sections = $this->sections($layout->sections($locale), $banners, $listing, $locale);
            if ($sections !== []) {
                return ['sections' => $sections];
            }

            $categories = $this->categories($locale);

            $rails = [];
            foreach (Collection::query()->with('translations')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->limit(6)->get() as $collection) {
                $cards = $listing->forCollection($collection->id, $locale, 8);
                if ($cards !== []) {
                    $rails[] = ['title' => (string) $collection->translate('name'), 'url' => url("/{$locale}/collections/".$collection->translate('slug')), 'cards' => $cards];
                }
                if (count($rails) === 3) {
                    break;
                }
            }

            return [
                'sections' => [],
                'hero' => $banners->active('home_hero', $locale),
                'strip' => $banners->active('home_strip', $locale),
                'categories' => $categories,
                'collections' => $rails,
                'newest' => $listing->newest($locale, 8),
                'popular' => $listing->popular($locale, 8),
            ];
        });

        return view('catalog::storefront.home', $data);
    }

    /** @return list<array{name: string, url: string, key: string}> the first twelve active top-level categories; `key` (the English slug) picks the tile's icon */
    private function categories(string $locale): array
    {
        $out = [];
        foreach (Category::query()->with('translations')->whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->limit(12)->get() as $c) {
            $out[] = ['name' => (string) $c->translate('name'), 'url' => url("/{$locale}/c/".$c->translate('slug')), 'key' => (string) ($c->translations->firstWhere('locale', 'en')->slug ?? '')];
        }

        return $out;
    }

    /**
     * Turns the staff-set blocks into what the page needs. A block with nothing to show (no banners, an inactive or empty collection) is left out.
     *
     * @param  list<HomeSectionView>  $views
     * @return list<array<string, mixed>>
     */
    private function sections(array $views, BannerSource $banners, ListingQuery $listing, string $locale): array
    {
        $out = [];
        foreach ($views as $view) {
            $block = match ($view->type) {
                'banner_hero' => ['banners' => $banners->active('home_hero', $locale)],
                'banner_strip' => ['banners' => $banners->active('home_strip', $locale)],
                'categories' => ['categories' => $this->categories($locale)],
                'new_arrivals' => ['cards' => $listing->newest($locale, 8), 'url' => url("/{$locale}/shop")],
                'popular' => ['cards' => $listing->popular($locale, 8), 'url' => url("/{$locale}/shop?sort=popular")],
                'collection' => $this->collectionBlock($view, $listing, $locale),
                default => ['body' => $view->body],
            };

            $content = match ($view->type) {
                'banner_hero', 'banner_strip' => $block['banners'] ?? [],
                'categories' => $block['categories'] ?? [],
                'new_arrivals', 'popular', 'collection' => $block['cards'] ?? [],
                default => $block['body'] ?? null,
            };
            if ($content === [] || $content === null || $content === '') {
                continue; // nothing to show: the block is left out
            }
            $out[] = ['type' => $view->type, 'title' => $block['title'] ?? $view->title] + $block;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function collectionBlock(HomeSectionView $view, ListingQuery $listing, string $locale): array
    {
        $collection = $view->collectionRef === null ? null : Collection::query()->with('translations')->where('public_id', $view->collectionRef)->where('is_active', true)->first();
        if ($collection === null) {
            return ['cards' => []];
        }

        return ['title' => $view->title ?? (string) $collection->translate('name'), 'url' => url("/{$locale}/collections/".$collection->translate('slug')), 'cards' => $listing->forCollection($collection->id, $locale, 8)];
    }
}
