<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ETag + If-None-Match for public reads (API_ARCHITECTURE §1). Usage: ->middleware('etag:config') where the argument names a
 * lifetime in config('api.cache_seconds'). The tag is a hash of the body AND the language, so a Bangla and an English answer never share one.
 * A matching If-None-Match gets 304 with no body. Only successful GET/HEAD answers are tagged.
 */
class ConditionalGet
{
    public function handle(Request $request, Closure $next, string $lifetime = 'catalog'): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable() || $response->getStatusCode() !== 200 || $response->headers->has('ETag')) {
            return $response;
        }

        $etag = '"'.substr(hash('sha256', app()->getLocale()."\n".(string) $response->getContent()), 0, 32).'"';
        $seconds = max(0, (int) config("api.cache_seconds.{$lifetime}", 0));

        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', $seconds > 0 ? "public, max-age={$seconds}" : 'no-cache');

        $sent = array_map(fn (string $t) => trim(preg_replace('/^W\//', '', trim($t)) ?? ''), explode(',', (string) $request->headers->get('If-None-Match', '')));
        if (in_array($etag, $sent, true) || in_array('*', $sent, true)) {
            $response->setNotModified();
        }

        return $response;
    }
}
