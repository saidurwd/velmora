<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Web;

use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageTranslation;
use App\Modules\Platform\Support\PageSeo;
use App\Modules\Platform\Support\SlugRedirects;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class PageController
{
    public function show(string $slug, PageSeo $seo, SlugRedirects $redirects): View|RedirectResponse
    {
        $locale = app()->getLocale();
        $translation = PageTranslation::query()->where('locale', $locale)->where('slug', $slug)->first();

        if ($translation === null) { // a retired slug leads to the page's current address
            $pageId = $redirects->resolve('page', $locale, $slug);
            $current = $pageId === null ? null : PageTranslation::query()->where('page_id', $pageId)->where('locale', $locale)->value('slug');

            return $current === null ? abort(404) : redirect()->to(url("/{$locale}/pages/".rawurlencode((string) $current)), 301);
        }

        $page = Page::query()->whereKey($translation->page_id)->firstOrFail();
        abort_unless($page->isLive(), 404); // drafts and not-yet-scheduled pages do not exist publicly

        $alternates = [];
        foreach (PageTranslation::query()->where('page_id', $page->id)->get() as $t) {
            $alternates[$t->locale] = url("/{$t->locale}/pages/".rawurlencode($t->slug));
        }
        $seo->canonical = url("/{$locale}/pages/".rawurlencode($translation->slug));
        $seo->alternates($alternates)->describe($translation->seo_description ?: $translation->body);

        return view('cms::page', ['page' => $page, 'translation' => $translation]);
    }
}
