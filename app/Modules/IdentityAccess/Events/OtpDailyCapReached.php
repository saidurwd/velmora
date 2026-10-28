<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Events;

/** Alert hook for the SMS-pumping guard: wire a staff notification to this in Phase 9. */
final readonly class OtpDailyCapReached
{
    public function __construct(public int $cap, public string $day) {}
}
