<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

use App\Modules\Customers\DTOs\CustomerProfile;

/** What other modules (IdentityAccess account pages, Checkout) may know about customers. */
interface CustomerProfiles
{
    public function findByUserId(int $userId): ?CustomerProfile;

    /** Idempotent: creates the profile for a user once, then returns it. */
    public function ensureForUser(int $userId, ?string $name, ?string $email, ?string $phone): CustomerProfile;

    public function updateName(int $userId, string $name): void;

    /** Name, optional birth date, and the marketing consent decision (timestamped when it changes). */
    public function updateProfile(int $userId, string $name, ?string $birthDate, bool $marketingConsent): void;
}
