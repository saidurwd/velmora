<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\DTOs;

use App\Modules\IdentityAccess\Enums\RefreshStatus;

final readonly class RefreshResult
{
    private function __construct(public RefreshStatus $status, public ?ApiTokenPair $pair = null) {}

    public static function renewed(ApiTokenPair $pair): self
    {
        return new self(RefreshStatus::Renewed, $pair);
    }

    public static function invalid(): self
    {
        return new self(RefreshStatus::Invalid);
    }

    public static function reused(): self
    {
        return new self(RefreshStatus::Reused);
    }
}
