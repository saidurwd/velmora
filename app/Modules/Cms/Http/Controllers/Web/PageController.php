<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Web;

use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageTranslation;
use Illuminate\Contracts\View\View;

class PageController
{
    public function show(string $slug): View
    {
        $translation = PageTranslation::query()->where('locale', app()->getLocale())->where('slug', $slug)->firstOrFail();
        $page = Page::query()->whereKey($translation->page_id)->firstOrFail();

        abort_unless($page->isLive(), 404); // drafts and not-yet-scheduled pages do not exist publicly

        return view('cms::page', ['page' => $page, 'translation' => $translation]);
    }
}
