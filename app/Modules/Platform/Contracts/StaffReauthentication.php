<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

/** Asks a signed-in staff member to type their password again before a sensitive step. Wrong tries are counted and, after a few, refused for a while. */
interface StaffReauthentication
{
    public function confirmPassword(int $staffId, string $password): bool;
}
