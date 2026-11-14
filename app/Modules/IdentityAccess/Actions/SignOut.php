<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ends a browser sign-in (the shop or the staff area) and writes it to the audit trail, so login history shows when each sign-in ended.
 * The app's sign-outs end their token sessions in EndApiSessions, which records them itself.
 */
final class SignOut
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  'web'|'admin'  $guard */
    public function handle(Request $request, string $guard): void
    {
        $user = Auth::guard($guard)->user();
        if ($user instanceof User) {
            $this->audit->record('auth.logout', $user, after: ['area' => $guard === 'admin' ? 'staff' : 'shop'], actor: new Actor($user->isStaff() ? ActorType::Staff : ActorType::Customer, $user->id, $user->public_id));
        }

        Auth::guard($guard)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
