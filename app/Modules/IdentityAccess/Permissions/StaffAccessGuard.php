<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Permissions;

use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Exceptions\LastSuperAdminException;
use App\Modules\IdentityAccess\Exceptions\PrivilegeEscalationException;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/** Rules that stop staff administration from locking everyone out or escalating privileges. Call inside a transaction. */
final class StaffAccessGuard
{
    /**
     * @param  list<string>  $roles  the target's roles AFTER the change
     *
     * @throws LastSuperAdminException
     */
    public function assertSuperAdminRemains(User $target, bool $targetStaysActive, array $roles): void
    {
        $role = Role::query()->where('name', PermissionCatalogue::SUPER_ADMIN)->where('guard_name', 'admin')->first();
        if ($role === null) {
            return;
        }

        // lock the assignments so two simultaneous demotions cannot both pass the check
        DB::table('model_has_roles')->where('role_id', $role->id)->lockForUpdate()->get();

        // Only someone who currently counts as an active Super Admin can be "the last one".
        if (! ($target->isActive() && $target->hasRole(PermissionCatalogue::SUPER_ADMIN))) {
            return;
        }

        $remaining = User::query()
            ->where('account_type', AccountType::Staff->value)
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($target->id)
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id))
            ->count();

        $targetWillBeSuperAdmin = $targetStaysActive && in_array(PermissionCatalogue::SUPER_ADMIN, $roles, true);

        if ($remaining === 0 && ! $targetWillBeSuperAdmin) {
            throw new LastSuperAdminException;
        }
    }

    /**
     * An actor may only hand out access they hold themselves; Super Admin is granted only by a Super Admin.
     *
     * @param  iterable<string>  $roleNames
     */
    public function assertCanGrantRoles(User $actor, iterable $roleNames): void
    {
        if ($actor->hasRole(PermissionCatalogue::SUPER_ADMIN)) {
            return;
        }

        foreach ($roleNames as $name) {
            if ($name === PermissionCatalogue::SUPER_ADMIN) {
                throw new PrivilegeEscalationException(PermissionCatalogue::SUPER_ADMIN);
            }

            $role = Role::query()->where('name', $name)->where('guard_name', 'admin')->firstOrFail();
            foreach ($role->permissions as $permission) {
                if (! $actor->can($permission->name)) {
                    throw new PrivilegeEscalationException("the role {$name}");
                }
            }
        }
    }

    /** @param  iterable<string>  $permissionNames */
    public function assertCanGrantPermissions(User $actor, iterable $permissionNames): void
    {
        if ($actor->hasRole(PermissionCatalogue::SUPER_ADMIN)) {
            return;
        }

        foreach ($permissionNames as $name) {
            if (! $actor->can($name)) {
                throw new PrivilegeEscalationException("the permission {$name}");
            }
        }
    }
}
