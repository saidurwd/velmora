<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Exceptions;

use DomainException;

final class LastSuperAdminException extends DomainException
{
    public function __construct()
    {
        parent::__construct('At least one active Super Admin must always remain.');
    }
}
