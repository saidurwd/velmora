<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells an app that a call is going away (docs/api/VERSIONING_AND_DEPRECATION.md). Usage: ->middleware('deprecated:2027-06-30') or
 * ->middleware('deprecated:2027-06-30,/api/v1/new-thing'). The answer is otherwise unchanged and carries `Deprecation: true`, `Sunset: <HTTP date>` (RFC 8594 / RFC 9745)
 * and, when a replacement is named, `Link: <…>; rel="successor-version"`. The sunset date is the first day the call may stop working; nothing is removed by this.
 */
class MarkDeprecated
{
    public function handle(Request $request, Closure $next, string $sunset, ?string $successor = null): Response
    {
        $response = $next($request);

        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Sunset', CarbonImmutable::parse($sunset, 'UTC')->startOfDay()->toRfc7231String());
        if ($successor !== null && $successor !== '') {
            $response->headers->set('Link', '<'.$successor.'>; rel="successor-version"', false);
        }

        return $response;
    }
}
