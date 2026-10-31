<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts;

/** Staff names for screens in other modules ("approved by …"). Names only: no emails, phones or other personal data. */
interface StaffDirectory
{
    /**
     * @param  list<int|null>  $userIds
     * @return array<int, string> keyed by user id
     */
    public function names(array $userIds): array;
}
