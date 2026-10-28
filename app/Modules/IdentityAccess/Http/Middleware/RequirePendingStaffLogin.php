<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Middleware;

use App\Modules\IdentityAccess\Mfa\PendingStaffLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The MFA pages are only reachable right after a correct staff password (and within five minutes of it). */
class RequirePendingStaffLogin
{
    public function __construct(private readonly PendingStaffLogin $pending) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->pending->userId() === null) {
            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
