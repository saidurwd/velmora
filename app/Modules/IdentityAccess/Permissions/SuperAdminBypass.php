<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Permissions;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;

/**
 * Explicit Gate::before hook: Super Admin passes every permission in the catalogue. It never applies to arbitrary
 * abilities, and use of a sensitive permission through the bypass is audited (once per request per permission).
 */
final class SuperAdminBypass
{
    /** @var array<string, true> */
    private array $audited = [];

    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(mixed $user, string $ability): ?bool
    {
        if (! $user instanceof User || ! $user->isStaff() || ! PermissionCatalogue::exists($ability) || ! $user->hasRole(PermissionCatalogue::SUPER_ADMIN)) {
            return null;
        }

        if (PermissionCatalogue::isSensitive($ability) && ! isset($this->audited[$ability])) {
            $this->audited[$ability] = true;
            $this->audit->record('rbac.super_admin_bypass', $user, after: ['permission' => $ability], actor: new Actor(ActorType::Staff, $user->id, $user->public_id));
        }

        return true;
    }
}
