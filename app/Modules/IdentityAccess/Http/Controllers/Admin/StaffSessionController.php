<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Admin;

use App\Modules\IdentityAccess\Sessions\UserSessionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Device list for the signed-in staff member: see where they are signed in and end other sessions. */
class StaffSessionController
{
    public function index(Request $request, UserSessionRegistry $registry): View
    {
        $user = $request->user('admin');

        return view('identity-access::admin.sessions', [
            'sessions' => $registry->listFor((int) $user?->getAuthIdentifier()),
            'currentHash' => hash('sha256', $request->session()->getId()),
        ]);
    }

    public function revokeOthers(Request $request, UserSessionRegistry $registry): RedirectResponse
    {
        $count = $registry->revokeOthers((int) $request->user('admin')?->getAuthIdentifier(), $request->session()->getId(), 'admin');

        return back()->with('status', __('identity-access::admin.sessions.revoked', ['count' => $count]));
    }
}
