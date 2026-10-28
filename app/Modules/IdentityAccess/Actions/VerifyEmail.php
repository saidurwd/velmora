<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;

final class VerifyEmail
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @return bool true only the first time (the link is single-use for sign-in purposes) */
    public function handle(User $user, string $hash): bool
    {
        if (! hash_equals(sha1((string) $user->email), $hash) || $user->email_verified_at !== null) {
            return false;
        }

        $user->forceFill(['email_verified_at' => $this->clock->now()])->save();
        $this->audit->record('auth.email_verified', $user, actor: new Actor(ActorType::Customer, $user->id, $user->public_id));

        return true;
    }
}
