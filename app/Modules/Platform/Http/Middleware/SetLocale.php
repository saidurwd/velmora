<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\DTOs\RequestContext;
use App\Modules\Platform\Events\LocaleChosen;
use App\Modules\Platform\Localization\LocaleResolver;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(private readonly LocaleResolver $resolver, private readonly RequestContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolver->resolve($request);

        app()->setLocale($locale);
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);
        $this->context->locale = $locale;
        URL::defaults(['locale' => $locale]); // so route('login') needs no explicit locale

        $request->route()?->forgetParameter('locale'); // controllers never receive it

        $explicit = $request->route() && LocaleResolver::isSupported($request->segment(1));
        if ($explicit && $request->cookie(LocaleResolver::COOKIE) !== $locale) {
            Cookie::queue(Cookie::make(LocaleResolver::COOKIE, $locale, 60 * 24 * 365, '/', null, null, false, false, 'lax'));
        }

        $user = $request->user();
        if ($explicit && $user !== null && ($user->locale ?? null) !== $locale) {
            Event::dispatch(new LocaleChosen($user->getAuthIdentifier(), $locale));
        }

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);
        $response->headers->set('Vary', trim($response->headers->get('Vary', '').', Accept-Language, Cookie', ', '));

        return $response;
    }
}
