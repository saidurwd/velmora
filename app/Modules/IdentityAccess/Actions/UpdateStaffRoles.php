<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Permissions\StaffAccessGuard;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;

final class UpdateStaffRoles
{
    public function __construct(private readonly AuditLogger $audit, private readonly StaffAccessGuard $guard) {}

    /** @param  list<string>  $roles */
    public function handle(User $actor, User $staff, string $name, array $roles): void
    {
        Transactions::run(function () use ($actor, $staff, $name, $roles): void {
            $before = ['name' => $staff->name, 'roles' => $staff->getRoleNames()->all()];

            $added = array_diff($roles, $before['roles']);
            $removed = array_diff($before['roles'], $roles);
            $this->guard->assertCanGrantRoles($actor, $added);
            $this->guard->assertCanGrantRoles($actor, $removed); // removing a role you could not grant is equally off-limits

            $this->guard->assertSuperAdminRemains($staff, $staff->isActive(), $roles);

            $staff->forceFill(['name' => $name])->save();
            $staff->syncRoles($roles);

            $this->audit->record('staff.updated', $staff, $before, ['name' => $name, 'roles' => $roles], new Actor(ActorType::Staff, $actor->id, $actor->public_id));
        });
    }
}
