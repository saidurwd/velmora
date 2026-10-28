<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Web;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Services\Storefront\ListingQuery;
use App\Modules\Cms\Contracts\BannerSource;
use Illuminate\Contracts\View\View;

/** The storefront home page: banners from the CMS, categories, curated collections, new arrivals, popular products. */
class HomeController
{
    public function __invoke(BannerSource $banners, ListingQuery $listing): View
    {
        $locale = app()->getLocale();

        $categories = Category::query()->with('translations')->whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->limit(8)->get()
            ->map(fn (Category $c) => ['name' => (string) $c->translate('name'), 'url' => url("/{$locale}/c/".$c->translate('slug'))])->all();

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

        return view('catalog::storefront.home', [
            'hero' => $banners->active('home_hero', $locale),
            'strip' => $banners->active('home_strip', $locale),
            'categories' => $categories,
            'collections' => $rails,
            'newest' => $listing->newest($locale, 8),
            'popular' => $listing->popular($locale, 8),
        ]);
    }
}
