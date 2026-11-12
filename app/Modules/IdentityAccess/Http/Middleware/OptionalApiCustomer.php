<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Middleware;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * For app routes a guest may use (cart, checkout): no `Authorization` header means a guest and the request goes on. A header that IS sent must be a
 * good customer token — a wrong, expired or staff token is refused (401) rather than quietly treated as a guest, so a signed-in app that lost its token
 * learns it at once and never shows (or fills) a guest cart by mistake.
 */
class OptionalApiCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null) {
            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();

        if (! $user instanceof User || $user->isStaff() || ! $user->isActive() || ! $user->tokenCan('customer')) {
            throw new ApiProblem(ErrorCode::Unauthenticated);
        }

        $request->setUserResolver(fn () => $user); // this request's user only: no global default guard is changed

        return $next($request);
    }
}
