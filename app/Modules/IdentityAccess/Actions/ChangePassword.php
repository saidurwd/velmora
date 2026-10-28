<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Notifications\SecurityChangeNotification;
use App\Modules\IdentityAccess\Sessions\UserSessionRegistry;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Support\Facades\Hash;

/** Sets a new password and ends every other session. The caller has already required recent re-authentication. */
final class ChangePassword
{
    public function __construct(private readonly AuditLogger $audit, private readonly UserSessionRegistry $sessions) {}

    public function handle(User $user, string $newPassword, string $currentSessionId): void
    {
        $hadPassword = $user->password !== null;
        $user->forceFill(['password' => $newPassword])->save();
        $this->sessions->revokeOthers($user->id, $currentSessionId);

        $this->audit->record($hadPassword ? 'auth.password_changed' : 'auth.password_set', $user, actor: new Actor(ActorType::Customer, $user->id, $user->public_id));

        if ($user->email !== null) {
            $user->notify(new SecurityChangeNotification('password'));
        }
    }

    public function matchesCurrent(User $user, string $password): bool
    {
        return $user->password !== null && Hash::check($password, $user->password);
    }
}
