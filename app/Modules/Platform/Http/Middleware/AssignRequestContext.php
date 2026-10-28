<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\DTOs\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Gives every request a correlation id (accepting a safe inbound X-Request-Id) and fills RequestContext. */
class AssignRequestContext
{
    public function __construct(private readonly RequestContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $requestId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) ? $incoming : strtolower((string) Str::ulid());

        $this->context->requestId = $requestId;
        $this->context->ip = $request->ip();
        $this->context->userAgent = $request->userAgent();
        $this->context->locale = app()->getLocale();
        $request->headers->set('X-Request-Id', $requestId);

        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
