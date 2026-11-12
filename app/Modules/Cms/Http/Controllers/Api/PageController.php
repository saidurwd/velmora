<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api;

use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageTranslation;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use App\Modules\Platform\Support\SlugRedirects;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;

class PageController
{
    /**
     * A page (policy, help, about)
     *
     * Published pages only. `body_html` is the shop's own sanitised HTML: show it in a web view or an HTML-capable text view. A page that exists only in another language, or only as a draft, is 404 (`page.not_found`). `GET /config` lists the pages and their slugs.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{slug: string, title: string, body_html: string, kind: string|null, locale: string}}')]
    public function show(string $slug, SlugRedirects $redirects): JsonResponse
    {
        $locale = app()->getLocale();
        $translation = PageTranslation::query()->where('locale', $locale)->where('slug', $slug)->first();

        if ($translation === null) { // a retired slug leads to the page's current address
            $pageId = $redirects->resolve('page', $locale, $slug);
            $translation = $pageId === null ? null : PageTranslation::query()->where('page_id', $pageId)->where('locale', $locale)->first();
        }

        $page = $translation === null ? null : Page::query()->whereKey($translation->page_id)->first();
        if ($translation === null || $page === null || ! $page->isLive()) {
            throw new ApiProblem(ErrorCode::PageNotFound);
        }

        return response()->json(['data' => ['slug' => $translation->slug, 'title' => $translation->title, 'body_html' => (string) $translation->body, 'kind' => $page->kind, 'locale' => $locale]]);
    }
}
