<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\SearchProviders;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/** The staff search box. The words typed are never logged (they can be a phone number). Each kind of result appears only for people who may open it. */
class SearchController
{
    public const MIN = 3;

    public const PER_KIND = 5;

    public function __invoke(Request $request, SearchProviders $providers): View
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:60']]);
        $term = BanglaText::input((string) ($data['q'] ?? ''));
        $user = $request->user('admin');
        $seePii = $user?->can('customers.view_pii') === true;

        $groups = [];
        if (mb_strlen($term) >= self::MIN) {
            foreach ($providers->visibleFor($user) as $provider) {
                try {
                    $hits = $provider->search($term, self::PER_KIND, $seePii);
                } catch (Throwable $e) {
                    Log::warning('admin.search_failed', ['provider' => $provider::class, 'kind' => $e::class]);

                    continue;
                }
                $hits === [] || $groups[] = ['kind' => $provider->kind(), 'hits' => $hits];
            }
        }

        return view('platform::admin.search', ['term' => $term, 'tooShort' => $term !== '' && mb_strlen($term) < self::MIN, 'groups' => $groups]);
    }
}
