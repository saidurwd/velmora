<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Notifications\StaffInviteNotification;
use App\Modules\IdentityAccess\Permissions\StaffAccessGuard;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** Creates a staff account with an unguessable placeholder password and emails an invite to choose a real one. */
final class CreateStaffUser
{
    public function __construct(private readonly AuditLogger $audit, private readonly StaffAccessGuard $guard) {}

    /** @param  list<string>  $roles */
    public function handle(User $actor, string $name, string $email, array $roles, string $locale = 'en'): User
    {
        $staff = Transactions::run(function () use ($actor, $name, $email, $roles, $locale): User {
            $this->guard->assertCanGrantRoles($actor, $roles);

            $user = new User;
            $user->fill(['name' => $name, 'email' => mb_strtolower(trim($email)), 'password' => Str::random(64), 'locale' => $locale]);
            $user->forceFill(['account_type' => AccountType::Staff, 'status' => UserStatus::Active])->save();
            $user->syncRoles($roles);

            $this->audit->record('staff.created', $user, after: ['email' => $user->email, 'roles' => $roles], actor: new Actor(ActorType::Staff, $actor->id, $actor->public_id));

            return $user;
        });

        $token = Password::broker('staff')->createToken($staff);
        $staff->notify(new StaffInviteNotification($token, (string) $actor->name));

        return $staff;
    }
}
