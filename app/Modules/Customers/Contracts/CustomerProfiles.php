<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

use App\Modules\Customers\DTOs\CustomerProfile;

/** What other modules (IdentityAccess account pages, Checkout) may know about customers. */
interface CustomerProfiles
{
    public function findByUserId(int $userId): ?CustomerProfile;

    /**
     * One query for a list of users (staff screens), keyed by user id; a user without a profile is simply missing.
     *
     * @param  list<int>  $userIds
     * @return array<int, CustomerProfile>
     */
    public function findManyByUserIds(array $userIds): array;

    /** Idempotent: creates the profile for a user once, then returns it. */
    public function ensureForUser(int $userId, ?string $name, ?string $email, ?string $phone): CustomerProfile;

    public function updateName(int $userId, string $name): void;

    /** Name and optional birth date. (What a customer agreed to receive lives in the Notifications module, in one place: /account/preferences.) */
    public function updateProfile(int $userId, string $name, ?string $birthDate): void;
}
