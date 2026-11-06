<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Web;

use App\Modules\Cms\Actions\SaveFaqEntry;
use App\Modules\Cms\Services\FaqReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** The public help centre: published questions by topic, with a search box. No customer data is involved, so anyone may read it. */
class HelpController
{
    public function show(Request $request, FaqReader $faq): View
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);

        return view('cms::faq.help', ['groups' => $faq->published(app()->getLocale(), $q), 'q' => $q, 'categories' => SaveFaqEntry::CATEGORIES]);
    }
}
