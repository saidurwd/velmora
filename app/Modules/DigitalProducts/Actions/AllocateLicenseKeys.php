<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Gives a customer's right the keys it is owed, one per unit, lowest key first. Two people (or two orders) taking keys at the same instant never get the
 * same one: the rows are taken with FOR UPDATE SKIP LOCKED, and the update only takes a key that is still available. If the pool has fewer keys than are
 * owed, the right gets what there is and the rest wait; nothing is invented and nothing fails. Safe to run again at any time.
 */
final class AllocateLicenseKeys
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @return int how many keys were given to this right now */
    public function forEntitlement(int $entitlementId): int
    {
        return Transactions::run(function () use ($entitlementId): int {
            // the right's own row is the lock: two allocations for the same right wait for each other, so it can never be given more than it is owed
            $right = DB::table('entitlements')->where('id', $entitlementId)->where('status', 'active')->lockForUpdate()->first(['id', 'variant_id', 'keys_needed']);
            if ($right === null || (int) $right->keys_needed < 1) {
                return 0;
            }
            $missing = (int) $right->keys_needed - DB::table('license_keys')->where('entitlement_id', $entitlementId)->where('status', '<>', 'revoked')->count();
            $pool = DB::table('license_key_pools')->where('variant_id', $right->variant_id)->value('id');
            if ($missing < 1 || $pool === null) {
                return 0;
            }

            $ids = DB::table('license_keys')->where('pool_id', $pool)->where('status', 'available')->orderBy('id')->limit($missing)->lock('for update skip locked')->pluck('id')->all();
            if ($ids === []) {
                return 0;
            }

            $now = $this->clock->now();
            $given = DB::table('license_keys')->whereIn('id', $ids)->where('status', 'available')->update(['status' => 'allocated', 'entitlement_id' => $entitlementId, 'allocated_at' => $now]);
            if ($given > 0) {
                $this->audit->record('digital.license_keys_allocated', 'entitlement', [], ['entitlement_id' => $entitlementId, 'key_ids' => array_map('intval', $ids), 'count' => $given], Actor::system('digital'), $entitlementId);
            }

            return $given;
        });
    }

    /**
     * Fills what is owed, oldest right first, from the keys now available (after staff import more, and as a safety net on a schedule).
     *
     * @return int how many keys were given
     */
    public function fillWaiting(?int $variantId = null, int $limit = 200): int
    {
        // rights that are owed keys: a right is owed more than it has (served by entitlements(variant_id, status))
        $waiting = DB::table('entitlements as e')->where('e.status', 'active')->where('e.keys_needed', '>', 0)->when($variantId !== null, fn ($q) => $q->where('e.variant_id', $variantId))
            ->whereRaw("e.keys_needed > (SELECT COUNT(*) FROM license_keys k WHERE k.entitlement_id = e.id AND k.status <> 'revoked')")->orderBy('e.id')->limit($limit)->pluck('e.id');

        $given = 0;
        foreach ($waiting as $id) {
            $given += $this->forEntitlement((int) $id);
        }

        return $given;
    }
}
