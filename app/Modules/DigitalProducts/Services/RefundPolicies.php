<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Services;

use App\Modules\DigitalProducts\Models\RefundPolicy;
use Illuminate\Support\Facades\DB;

/** Which refund policy applies to a product: its own if staff chose one, else the shop's default. */
final class RefundPolicies
{
    /**
     * @param  list<int>  $variantIds
     * @return array<int, RefundPolicy> variant id => its policy (translations loaded); a variant with no policy at all (no default set) is left out
     */
    public function forVariants(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $own = DB::table('digital_assets')->whereIn('variant_id', $variantIds)->whereNotNull('refund_policy_id')->pluck('refund_policy_id', 'variant_id');   // unique variant_id
        $default = RefundPolicy::query()->where('is_default', true)->first();
        $byId = RefundPolicy::query()->with('translations')->whereIn('id', $own->values()->unique()->all())->get()->keyBy('id');
        $default?->load('translations');

        $out = [];
        foreach ($variantIds as $variantId) {
            $policy = isset($own[$variantId]) ? ($byId[(int) $own[$variantId]] ?? $default) : $default;
            if ($policy !== null) {
                $out[$variantId] = $policy;
            }
        }

        return $out;
    }

    public function forVariant(int $variantId): ?RefundPolicy
    {
        return $this->forVariants([$variantId])[$variantId] ?? null;
    }
}
