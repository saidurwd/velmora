<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Services;

use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\DigitalProducts\Models\RefundPolicy;
use Carbon\CarbonImmutable;

/** What the refund policy says about one customer's download. Advice for staff; the refund itself is decided in Payments. */
final class RefundVerdict
{
    /** @return array{refundable: bool, reason: string} reason: not_refundable | unused | used | within_window | window_closed */
    public function for(Entitlement $entitlement, RefundPolicy $policy, CarbonImmutable $now): array
    {
        return match ($policy->rule) {
            'non_refundable' => ['refundable' => false, 'reason' => 'not_refundable'],
            'until_first_use' => $entitlement->first_used_at === null ? ['refundable' => true, 'reason' => 'unused'] : ['refundable' => false, 'reason' => 'used'],
            default => $entitlement->granted_at->addDays((int) $policy->days)->greaterThan($now) ? ['refundable' => true, 'reason' => 'within_window'] : ['refundable' => false, 'reason' => 'window_closed'],
        };
    }
}
