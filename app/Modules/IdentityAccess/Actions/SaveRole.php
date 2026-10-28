<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Permissions\PermissionCatalogue;
use App\Modules\IdentityAccess\Permissions\StaffAccessGuard;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use DomainException;
use Spatie\Permission\Models\Role;

/** Creates a custom role or edits a role's permissions. The Super Admin role is immutable. */
final class SaveRole
{
    public function __construct(private readonly AuditLogger $audit, private readonly StaffAccessGuard $guard) {}

    /** @param  list<string>  $permissions */
    public function handle(User $actor, ?Role $role, string $name, array $permissions): Role
    {
        if ($role?->name === PermissionCatalogue::SUPER_ADMIN) {
            throw new DomainException('The Super Admin role cannot be edited.');
        }

        $permissions = array_values(array_filter($permissions, fn (string $p) => PermissionCatalogue::exists($p))); // fail closed on unknown names

        return Transactions::run(function () use ($actor, $role, $name, $permissions): Role {
            $before = $role === null ? [] : ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->sort()->values()->all()];

            $current = $role?->permissions->pluck('name')->all() ?? [];
            $this->guard->assertCanGrantPermissions($actor, array_merge(array_diff($permissions, $current), array_diff($current, $permissions)));

            if ($role === null) {
                $role = Role::query()->create(['name' => $name, 'guard_name' => 'admin']);
            } elseif (! PermissionCatalogue::isSystemRole($role->name)) {
                $role->forceFill(['name' => $name])->save(); // system role names are fixed
            }

            $role->syncPermissions($permissions);

            $this->audit->record($before === [] ? 'role.created' : 'role.updated', $role, $before, ['name' => $role->name, 'permissions' => collect($permissions)->sort()->values()->all()], new Actor(ActorType::Staff, $actor->id, $actor->public_id));

            return $role;
        });
    }
}
