<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Web;

use App\Modules\Platform\Events\LocaleChosen;
use App\Modules\Platform\Localization\LocaleResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;

class LocaleController
{
    /** `/` -> the visitor's best locale (cookie -> Accept-Language -> default). */
    public function root(Request $request, LocaleResolver $resolver): RedirectResponse
    {
        return redirect('/'.$resolver->resolve($request), 302);
    }

    /** For pages that are not locale-prefixed (admin, account settings): remember the choice and go back. */
    public function set(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'string', 'in:'.implode(',', LocaleResolver::supported())]]);

        Cookie::queue(Cookie::make(LocaleResolver::COOKIE, $data['locale'], 60 * 24 * 365, '/', null, null, false, false, 'lax'));

        if (($user = $request->user()) !== null && ($user->locale ?? null) !== $data['locale']) {
            Event::dispatch(new LocaleChosen($user->getAuthIdentifier(), $data['locale']));
        }

        return redirect($this->safeBack($request));
    }

    private function safeBack(Request $request): string
    {
        $previous = (string) url()->previous();
        $host = $request->getSchemeAndHttpHost();

        if (! str_starts_with($previous, $host.'/')) {
            return '/'; // never redirect off-site
        }

        $path = substr($previous, strlen($host));
        // a localized page: land on the same page in the new locale
        if (LocaleResolver::isSupported(explode('/', ltrim($path, '/'))[0])) {
            return '/'.$request->input('locale').substr($path, strlen('/'.explode('/', ltrim($path, '/'))[0]));
        }

        return $path;
    }
}
