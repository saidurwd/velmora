<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Middleware;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `auth:sanctum` on every app route that needs a person: the token must be a customer's (ability `customer`), the account must be
 * a customer account and still active. A staff member's token, a token of a disabled or locked account, or one without the ability is
 * refused exactly like a missing token.
 */
class EnsureApiCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isStaff() || ! $user->isActive() || ! $user->tokenCan('customer')) {
            throw new ApiProblem(ErrorCode::Unauthenticated);
        }

        return $next($request);
    }
}
