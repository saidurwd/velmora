<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Platform\DTOs\PhoneNumber;
use Throwable;

/** How a person is written in the delivery record: enough for staff to recognise, not enough to misuse. */
final class Masker
{
    public function phone(string $phone): ?string
    {
        try {
            return PhoneNumber::parse($phone)->masked();
        } catch (Throwable) {
            return null;
        }
    }

    public function email(string $email): ?string
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 1).'***@'.$domain;
    }
}
