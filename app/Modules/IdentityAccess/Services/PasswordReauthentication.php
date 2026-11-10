<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Services;

use App\Modules\IdentityAccess\Actions\ConfirmRecentAuth;
use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Contracts\StaffReauthentication;

final class PasswordReauthentication implements StaffReauthentication
{
    public function __construct(private readonly ConfirmRecentAuth $confirm) {}

    public function confirmPassword(int $staffId, string $password): bool
    {
        $user = User::query()->whereKey($staffId)->where('account_type', AccountType::Staff->value)->first();

        return $user !== null && $this->confirm->withPassword($user, $password);
    }
}
