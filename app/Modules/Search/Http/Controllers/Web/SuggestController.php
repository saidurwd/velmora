<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Controllers\Web;

use App\Modules\Search\Contracts\CatalogSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Autocomplete: a short JSON list of product names and addresses. Never logged, never personalised, so it may be cached briefly. */
class SuggestController
{
    public function __invoke(Request $request, CatalogSearch $search): JsonResponse
    {
        $raw = $request->query('q');
        $q = is_string($raw) ? trim(mb_substr($raw, 0, 100)) : '';
        $locale = app()->getLocale();

        $items = mb_strlen($q) < 2 ? [] : array_map(
            fn ($s) => ['name' => $s->name, 'url' => url("/{$locale}/p/".rawurlencode($s->slug))],
            $search->suggest($q, $locale, 6),
        );

        return response()->json(['items' => $items])->header('Cache-Control', 'public, max-age=60');
    }
}
