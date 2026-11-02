<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Actions;

use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\DigitalProducts\Services\RefundPolicies;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Takes a customer's downloads and keys away. A revoked right never comes back (the database refuses it); keys that were given are withdrawn, not returned to
 * the pool. Two ways in: staff revoke one right with a reason (`digital.manage`, decided by the caller), or a fully refunded order loses the rights whose
 * refund policy says so. Doing it twice is harmless.
 */
final class RevokeEntitlements
{
    public function __construct(private readonly RefundPolicies $policies, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws DigitalException entitlement_not_found | entitlement_already_revoked | reason_required */
    public function one(Actor $actor, string $entitlementPublicId, string $reason): Entitlement
    {
        $reason = mb_substr(trim(BanglaText::input($reason)), 0, 255);
        if ($reason === '') {
            throw new DigitalException('reason_required');
        }

        return Transactions::run(function () use ($actor, $entitlementPublicId, $reason): Entitlement {
            $right = Entitlement::query()->where('public_id', $entitlementPublicId)->lockForUpdate()->first() ?? throw new DigitalException('entitlement_not_found');
            if ($right->status->value !== 'active') {
                throw new DigitalException('entitlement_already_revoked');
            }
            $this->revoke($right, $reason, $actor);

            return $right->refresh();
        });
    }

    /** @return int how many rights were revoked: those of this order whose policy withdraws access on a full refund */
    public function forRefundedOrder(int $orderId): int
    {
        return Transactions::run(function () use ($orderId): int {
            $rights = Entitlement::query()->where('order_id', $orderId)->where('status', 'active')->orderBy('id')->lockForUpdate()->get();   // index order_id
            $policies = $this->policies->forVariants(array_values(array_unique(array_map('intval', $rights->pluck('variant_id')->all()))));
            $count = 0;
            foreach ($rights as $right) {
                $policy = $policies[$right->variant_id] ?? null;
                if ($policy !== null && ! $policy->revoke_on_full_refund) {
                    continue;   // this product keeps working after a refund, by its own policy
                }
                $this->revoke($right, 'refund', Actor::system('digital'));
                $count++;
            }

            return $count;
        });
    }

    private function revoke(Entitlement $right, string $reason, Actor $actor): void
    {
        $now = $this->clock->now();
        DB::table('entitlements')->where('id', $right->id)->where('status', 'active')->update(['status' => 'revoked', 'revoked_at' => $now, 'revoked_reason' => $reason, 'updated_at' => $now]);
        $keys = DB::table('license_keys')->where('entitlement_id', $right->id)->where('status', 'allocated')->update(['status' => 'revoked']);   // withdrawn, never given to anyone else
        $this->audit->record('digital.entitlement_revoked', 'entitlement', ['status' => 'active'], ['status' => 'revoked', 'reason' => $reason, 'keys_withdrawn' => $keys, 'order_id' => $right->order_id], $actor, $right->id);
    }
}
