<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Listeners;

use App\Modules\IdentityAccess\Sessions\UserSessionRegistry;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

final class TrackSession
{
    public function __construct(private readonly UserSessionRegistry $registry, private readonly Request $request) {}

    public function onLogin(Login $event): void
    {
        if (! $this->request->hasSession()) {
            return; // token/API logins are not browser sessions
        }

        $this->registry->register((int) $event->user->getAuthIdentifier(), $event->guard, $this->request->session()->getId(), $this->request->ip(), $this->request->userAgent());
    }

    public function onLogout(Logout $event): void
    {
        if ($this->request->hasSession()) {
            $this->registry->forget($this->request->session()->getId());
        }
    }
}
