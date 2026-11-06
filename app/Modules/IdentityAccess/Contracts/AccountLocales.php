<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts;

/** The language each account chose, for messages sent to customers who are not on the site at that moment. */
interface AccountLocales
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, string> keyed by user id
     */
    public function forUsers(array $userIds): array;
}
