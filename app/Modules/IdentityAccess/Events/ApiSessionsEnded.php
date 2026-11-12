<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Events;

/** App sign-ins ended (logout, theft, password change, disabled account). `familyIds` null means every sign-in of the person. Modules that hold per-sign-in things (push devices) listen. */
final readonly class ApiSessionsEnded
{
    /** @param  list<string>|null  $familyIds */
    public function __construct(public int $userId, public ?array $familyIds) {}
}
