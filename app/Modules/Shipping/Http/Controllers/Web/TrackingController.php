<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Web;

use App\Modules\Platform\Localization\LocaleResolver;
use App\Modules\Platform\Support\PageSeo;
use App\Modules\Shipping\Services\OrderTracking;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** Where is my order? Reached only through a link WE signed (the confirmation page, the "my orders" page); the signature is the proof the customer holds the order. */
class TrackingController
{
    public function show(Request $request, OrderTracking $tracking, PageSeo $seo, string $order): View
    {
        $data = $tracking->for($order, app()->getLocale()) ?? abort(404);

        // The signature covers the language in the path, so the language switcher must be given a link signed for each language (same expiry: the
        // link's life is not extended). Without this the switcher would lead to a page that refuses the unsigned address.
        $expires = CarbonImmutable::createFromTimestamp((int) $request->query('expires'));
        $seo->noindex = true; // a private page: never for search engines
        $seo->alternates(collect(LocaleResolver::supported())->mapWithKeys(fn (string $locale): array => [$locale => URL::temporarySignedRoute('orders.track', $expires, ['locale' => $locale, 'order' => $order])])->all());

        return view('shipping::track.show', ['track' => $data]);
    }
}
