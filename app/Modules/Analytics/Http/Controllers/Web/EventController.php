<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers\Web;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Receives storefront events from the consent-gated script. Honours Do-Not-Track and Global Privacy Control even if the
 * script was somehow run, accepts only three known events, stores nothing about the visitor, and always answers 204 to a
 * well-formed request so the page never waits on it.
 */
class EventController
{
    public function __invoke(Request $request, Clock $clock): Response
    {
        if ($request->header('DNT') === '1' || $request->header('Sec-GPC') === '1') {
            return response()->noContent();
        }

        if (! $this->sameOrigin($request)) {
            return response('', 403); // events come from our own pages, nothing else
        }

        $validator = Validator::make((array) json_decode((string) $request->getContent(), true), [
            'name' => ['required', 'in:page_view,search,view_item'],
            'path' => ['required', 'string', 'max:255', 'starts_with:/'],
            'product' => ['nullable', 'string', 'size:26', 'alpha_num'],
            'results' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        if ($validator->fails()) {
            return response('', 422);
        }

        $data = $validator->validated();

        DB::table('analytics_events')->insert([
            'name' => $data['name'],
            'locale' => app()->getLocale(),
            'path' => mb_substr((string) strtok($data['path'], '?#'), 0, 255), // never a query string: it can hold search words or tokens
            'product_public_id' => $data['name'] === 'view_item' ? strtolower((string) ($data['product'] ?? '')) ?: null : null,
            'result_count' => $data['name'] === 'search' ? ($data['results'] ?? null) : null,
            'created_at' => $clock->now()->format('Y-m-d H:i:s.u'),
        ]);

        return response()->noContent();
    }

    /** Browsers say where a request comes from (Sec-Fetch-Site, Origin); when they say "elsewhere", drop it. */
    private function sameOrigin(Request $request): bool
    {
        $site = $request->header('Sec-Fetch-Site');
        if ($site !== null) {
            return in_array($site, ['same-origin', 'same-site', 'none'], true);
        }

        $origin = $request->header('Origin');

        return $origin === null || parse_url($origin, PHP_URL_HOST) === $request->getHost();
    }
}
