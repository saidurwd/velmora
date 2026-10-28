<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\DTOs;

use App\Modules\IdentityAccess\Enums\LoginStatus;
use App\Modules\IdentityAccess\Models\User;

final readonly class LoginOutcome
{
    private function __construct(public LoginStatus $status, public ?User $user = null, public ?int $retryAfterSeconds = null) {}

    public static function success(User $user): self
    {
        return new self(LoginStatus::Success, $user);
    }

    public static function failed(): self
    {
        return new self(LoginStatus::Failed);
    }

    public static function throttled(int $seconds): self
    {
        return new self(LoginStatus::Throttled, retryAfterSeconds: $seconds);
    }

    public function succeeded(): bool
    {
        return $this->status === LoginStatus::Success;
    }
}
