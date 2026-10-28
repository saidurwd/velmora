<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Permissions\PermissionCatalogue;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use DomainException;
use Spatie\Permission\Models\Role;

final class DeleteRole
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, Role $role): void
    {
        if (PermissionCatalogue::isSystemRole($role->name)) {
            throw new DomainException('System roles cannot be deleted.');
        }

        if ($role->users()->exists()) {
            throw new DomainException('This role is still assigned to staff.');
        }

        $this->audit->record('role.deleted', $role, ['name' => $role->name], actor: new Actor(ActorType::Staff, $actor->id, $actor->public_id));
        $role->delete();
    }
}
