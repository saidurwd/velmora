<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Permissions\StaffAccessGuard;
use App\Modules\IdentityAccess\Sessions\UserSessionRegistry;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use DomainException;

/** Disabling signs the person out of every device immediately. */
final class SetStaffStatus
{
    public function __construct(private readonly AuditLogger $audit, private readonly StaffAccessGuard $guard, private readonly UserSessionRegistry $sessions) {}

    public function handle(User $actor, User $staff, UserStatus $status): void
    {
        if ($staff->is($actor) && $status !== UserStatus::Active) {
            throw new DomainException('You cannot disable your own account.');
        }

        Transactions::run(function () use ($actor, $staff, $status): void {
            if ($status !== UserStatus::Active) {
                $this->guard->assertSuperAdminRemains($staff, false, array_values(array_map('strval', $staff->getRoleNames()->all())));
            }

            $before = $staff->status->value;
            $staff->forceFill(['status' => $status])->save();
            $this->audit->record($status === UserStatus::Active ? 'staff.enabled' : 'staff.disabled', $staff, ['status' => $before], ['status' => $status->value], new Actor(ActorType::Staff, $actor->id, $actor->public_id));
        });

        if ($status !== UserStatus::Active) {
            $this->sessions->revokeAll($staff->id);
        }
    }
}
