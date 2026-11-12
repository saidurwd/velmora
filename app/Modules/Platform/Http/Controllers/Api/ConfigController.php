<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Api;

use App\Modules\Platform\Support\Api\ApiConfig;
use Illuminate\Http\JsonResponse;

class ConfigController
{
    /**
     * App bootstrap
     *
     * Everything an app needs before it shows anything: languages, currency, how prices are shown, the oldest app version still served,
     * how to reach the shop, and the pages (policies, help) it can link to. Cached for a few minutes; send If-None-Match to ask cheaply.
     *
     * @unauthenticated
     *
     * @response array{data: array{
     *   locale: string,
     *   languages: array{default: string, supported: list<array{code: string, name: string}>},
     *   currency: array{code: string, symbol: string, decimals: int, example: array{amount_minor: int, currency: string, formatted: string}},
     *   tax: array{enabled: bool, prices_include_tax: bool},
     *   app: array{min_supported_version: array{android: string, ios: string}, latest_version: array{android: string, ios: string}, store_urls: array{android: string|null, ios: string|null}},
     *   shop: array{name: string, phone: string, email: string},
     *   pages: list<array{slug: string, title: string}>
     * }}
     */
    public function show(ApiConfig $config): JsonResponse
    {
        $locale = app()->getLocale();

        return response()->json(['data' => ['locale' => $locale] + $config->build($locale)]);
    }
}
