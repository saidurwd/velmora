<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\DTOs;

use App\Modules\IdentityAccess\Models\User;

/** What a sign-in or a refresh hands the app. The plain tokens exist only here, once; the database keeps hashes. */
final readonly class ApiTokenPair
{
    public function __construct(
        public string $accessToken,
        public int $accessExpiresIn,
        public string $refreshToken,
        public int $refreshExpiresIn,
        public string $sessionId,
        public User $user,
    ) {}
}
