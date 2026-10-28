<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Notifications\AccountAlreadyExistsNotification;
use App\Modules\IdentityAccess\Notifications\ConfirmNewEmailNotification;
use App\Modules\IdentityAccess\Notifications\SecurityChangeNotification;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Support\Facades\Notification;

/**
 * Two steps, each proving something: (1) request sends a signed link to the NEW address (proves ownership);
 * (2) confirm applies it. The link is bound to the old address, so an old link cannot flip the email back later.
 */
final class ChangeEmail
{
    public function __construct(private readonly AuditLogger $audit, private readonly Clock $clock) {}

    public function request(User $user, string $newEmail): void
    {
        $newEmail = mb_strtolower(trim($newEmail));

        if ($newEmail === mb_strtolower((string) $user->email)) {
            return;
        }

        $taken = User::query()->where('email', $newEmail)->first();

        if ($taken !== null) {
            $taken->notify(new AccountAlreadyExistsNotification); // same neutral response to the requester either way

            return;
        }

        Notification::route('mail', $newEmail)->notify(new ConfirmNewEmailNotification($user->public_id, $newEmail, sha1((string) $user->email), $user->locale));
        $this->audit->record('auth.email_change_requested', $user, actor: new Actor(ActorType::Customer, $user->id, $user->public_id));
    }

    /** @return bool true when the address was changed */
    public function confirm(User $user, string $newEmail, string $oldEmailHash): bool
    {
        $newEmail = mb_strtolower(trim($newEmail));

        if (! hash_equals(sha1((string) $user->email), $oldEmailHash) || User::query()->where('email', $newEmail)->whereKeyNot($user->id)->exists()) {
            return false;
        }

        $old = $user->email;
        $user->forceFill(['email' => $newEmail, 'email_verified_at' => $this->clock->now()])->save();
        $this->audit->record('auth.email_changed', $user, ['email' => $old], ['email' => $newEmail], new Actor(ActorType::Customer, $user->id, $user->public_id));

        if ($old !== null) {
            Notification::route('mail', $old)->notify(new SecurityChangeNotification('email', $user->locale));
        }

        return true;
    }
}
