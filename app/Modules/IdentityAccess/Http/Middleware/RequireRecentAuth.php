<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Middleware;

use App\Modules\IdentityAccess\Auth\RecentAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sensitive account pages need a password/OTP confirmation within the last 10 minutes. */
class RequireRecentAuth
{
    public function __construct(private readonly RecentAuth $recent) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->recent->isFresh()) {
            return $next($request);
        }

        // remember where they were going; GET only so a lost POST body is never replayed
        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('account.confirm', ['locale' => app()->getLocale()]);
    }
}
