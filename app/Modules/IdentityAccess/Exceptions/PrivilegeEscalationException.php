<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Exceptions;

use DomainException;

final class PrivilegeEscalationException extends DomainException
{
    public function __construct(string $what)
    {
        parent::__construct("You cannot grant {$what}: it exceeds your own access.");
    }
}
