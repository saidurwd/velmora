<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Modules\Cms\Services\TranslationGaps;
use Illuminate\Contracts\View\View;

class TranslationGapController
{
    public function index(TranslationGaps $gaps): View
    {
        $default = (string) config('app.default_locale');
        $others = array_values(array_filter((array) config('app.supported_locales'), fn ($l) => $l !== $default));

        return view('cms::admin.translations.index', ['gaps' => array_combine($others, array_map(fn ($l) => $gaps->forLocale((string) $l), $others)), 'labels' => config('velmora.locale_labels')]);
    }
}
