<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

/** Whether a person has any signed-in phone to push to, so another module can leave out a push that could only be skipped. */
interface PushAudience
{
    public function reaches(int $userId): bool;
}
