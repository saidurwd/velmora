<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Middleware;

use App\Modules\IdentityAccess\Sessions\UserSessionRegistry;
use App\Modules\Platform\Contracts\Clock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies to authenticated sessions: rejects sessions that were revoked ("log out other devices", password reset),
 * enforces idle timeout (staff 30 min, customers 12 h) and records last activity (at most once a minute).
 * Usage: ->middleware('session.policy:web') or 'session.policy:admin'.
 */
class EnforceSessionPolicy
{
    private const IDLE_MINUTES = ['web' => 720, 'admin' => 30];

    public function __construct(private readonly UserSessionRegistry $registry, private readonly Clock $clock) {}

    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        if (! Auth::guard($guard)->check() || ! $request->hasSession()) {
            return $next($request);
        }

        $sessionId = $request->session()->getId();
        $row = $this->registry->find($sessionId);

        $idleLimit = self::IDLE_MINUTES[$guard] ?? 720;
        $lastSeen = $row === null ? null : CarbonImmutable::parse($row->last_seen_at, 'UTC');

        if ($row === null || $this->clock->now()->diffInMinutes($lastSeen, true) >= $idleLimit) {
            Auth::guard($guard)->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(route($guard === 'admin' ? 'admin.login' : 'login', $guard === 'admin' ? [] : ['locale' => app()->getLocale()]));
        }

        if ($this->clock->now()->diffInSeconds($lastSeen, true) >= 60) {
            $this->registry->touch($sessionId);
        }

        return $next($request);
    }
}
