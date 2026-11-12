<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Middleware;

use App\Modules\IdentityAccess\Auth\ApiReauth;
use App\Modules\IdentityAccess\Services\DatabaseApiSessions;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app's version of the website's `recent.auth`: sensitive changes (contact details, privacy requests) need this sign-in to have confirmed it is the person
 * within the last few minutes (`POST /auth/reauth`). Without it: 403 `auth.reauth_required`, and the app asks for the password or a code and tries again.
 */
class RequireApiRecentAuth
{
    public function __construct(private readonly DatabaseApiSessions $sessions, private readonly ApiReauth $reauth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $family = $this->sessions->currentFamily($request);

        if ($family === null || ! $this->reauth->isFresh($family)) {
            throw new ApiProblem(ErrorCode::ReauthRequired);
        }

        return $next($request);
    }
}
