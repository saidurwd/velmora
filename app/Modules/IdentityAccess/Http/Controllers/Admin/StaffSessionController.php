<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Admin;

use App\Modules\IdentityAccess\Sessions\UserSessionRegistry;
use App\Modules\Platform\DTOs\PersonRef;
use App\Modules\Platform\Services\AuditFeed;
use App\Modules\Platform\Support\AuditCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Device list for the signed-in staff member: where they are signed in (end the others) and their recent sign-ins and failed attempts. */
class StaffSessionController
{
    public function index(Request $request, UserSessionRegistry $registry, AuditFeed $feed): View
    {
        $user = $request->user('admin');
        $me = $user === null ? null : new PersonRef((int) $user->getAuthIdentifier(), (string) $user->public_id, (string) $user->name, true);

        return view('identity-access::admin.sessions', [
            'sessions' => $registry->listFor((int) $user?->getAuthIdentifier()),
            'currentHash' => hash('sha256', $request->session()->getId()),
            'recent' => $me === null ? collect() : $feed->page(['actions' => array_keys(AuditCatalog::SIGN_IN), 'person' => $me], null, 10)['rows'],
        ]);
    }

    public function revokeOthers(Request $request, UserSessionRegistry $registry): RedirectResponse
    {
        $count = $registry->revokeOthers((int) $request->user('admin')?->getAuthIdentifier(), $request->session()->getId(), 'admin');

        return back()->with('status', __('identity-access::admin.sessions.revoked', ['count' => $count]));
    }
}
