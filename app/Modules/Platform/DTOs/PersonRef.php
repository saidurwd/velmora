<?php

declare(strict_types=1);

namespace App\Modules\Platform\DTOs;

/** A person named in the audit trail, for the security and activity screens: who they are, nothing more (no email, phone or address). */
final readonly class PersonRef
{
    public function __construct(public int $id, public string $publicId, public string $name, public bool $isStaff) {}
}
