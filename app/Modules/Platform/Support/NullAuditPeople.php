<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Contracts\AuditPeople;
use App\Modules\Platform\DTOs\PersonRef;

/** Used only when no module provides AuditPeople: the screens still work and show "Someone" instead of a name. */
final class NullAuditPeople implements AuditPeople
{
    public function byIds(array $ids): array
    {
        return [];
    }

    public function byPublicIds(array $publicIds): array
    {
        return [];
    }

    public function accountSubjectTypes(): array
    {
        return [];
    }

    public function staff(): array
    {
        return [];
    }

    public function customerByContact(string $emailOrPhone): ?PersonRef
    {
        return null;
    }
}
