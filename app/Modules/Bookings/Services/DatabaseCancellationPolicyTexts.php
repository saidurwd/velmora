<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use App\Modules\Bookings\Contracts\CancellationPolicyTexts;
use Illuminate\Support\Facades\DB;

final class DatabaseCancellationPolicyTexts implements CancellationPolicyTexts
{
    public function forSkus(array $skus, string $locale): array
    {
        if ($skus === []) {
            return [];
        }

        // each SKU's service uses its own policy, else the default; the same policy for several services is shown once
        $default = DB::table('cancellation_policies')->where('default_marker', 1)->value('id');   // unique default_marker
        $policyIds = [];
        foreach (DB::table('product_variants as v')->join('service_packages as p', 'p.variant_id', '=', 'v.id')->join('service_definitions as s', 's.id', '=', 'p.service_id')
            ->whereIn('v.sku', $skus)->get(['s.cancellation_policy_id']) as $row) {   // unique sku, unique variant_id
            $id = $row->cancellation_policy_id ?? $default;
            if ($id !== null) {
                $policyIds[(int) $id] = true;
            }
        }

        $out = [];
        $fallback = (string) config('app.fallback_locale');
        foreach (array_keys($policyIds) as $id) {
            $words = DB::table('cancellation_policy_translations')->where('policy_id', $id)->whereIn('locale', array_unique([$locale, $fallback]))->get(['locale', 'title', 'text'])->keyBy('locale');   // unique (policy_id, locale)
            $w = $words->get($locale) ?? $words->get($fallback);
            if ($w !== null) {
                $out[] = ['title' => (string) $w->title, 'text' => (string) $w->text];
            }
        }

        return $out;
    }
}
