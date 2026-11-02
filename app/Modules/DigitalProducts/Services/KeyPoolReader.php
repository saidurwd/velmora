<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Services;

use Illuminate\Support\Facades\DB;

/** How many keys a product has, in each state, and how many are owed to customers who are waiting. Counts only: never a key. */
final class KeyPoolReader
{
    /** @return array{exists: bool, available: int, allocated: int, revoked: int, waiting: int} */
    public function forVariant(int $variantId): array
    {
        $pool = DB::table('license_key_pools')->where('variant_id', $variantId)->value('id');
        if ($pool === null) {
            return ['exists' => false, 'available' => 0, 'allocated' => 0, 'revoked' => 0, 'waiting' => 0];
        }

        $counts = DB::table('license_keys')->where('pool_id', $pool)->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status');   // index (pool_id, status, id)
        $owed = (int) DB::table('entitlements as e')->where('e.variant_id', $variantId)->where('e.status', 'active')->where('e.keys_needed', '>', 0)->selectRaw("COALESCE(SUM(e.keys_needed - (SELECT COUNT(*) FROM license_keys k WHERE k.entitlement_id = e.id AND k.status <> 'revoked')), 0) as owed")->value('owed');

        return ['exists' => true, 'available' => (int) ($counts['available'] ?? 0), 'allocated' => (int) ($counts['allocated'] ?? 0), 'revoked' => (int) ($counts['revoked'] ?? 0), 'waiting' => max(0, $owed)];
    }

    /** @return list<int> the variants whose waiting customers outnumber the keys left */
    public function shortVariantIds(): array
    {
        $short = [];
        foreach (DB::table('license_key_pools')->pluck('variant_id') as $variantId) {
            $c = $this->forVariant((int) $variantId);
            if ($c['waiting'] > $c['available']) {
                $short[] = (int) $variantId;
            }
        }

        return $short;
    }
}
