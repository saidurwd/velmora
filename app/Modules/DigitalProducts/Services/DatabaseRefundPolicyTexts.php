<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Services;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\DigitalProducts\Contracts\RefundPolicyTexts;

final class DatabaseRefundPolicyTexts implements RefundPolicyTexts
{
    public function __construct(private readonly VariantDirectory $variants, private readonly RefundPolicies $policies) {}

    public function forSkus(array $skus, string $locale): array
    {
        $ids = [];
        foreach ($skus as $sku) {
            $variant = $this->variants->findBySku($sku);
            if ($variant !== null && $variant->productType === 'digital') {
                $ids[] = $variant->id;
            }
        }

        /** @var array<int, array{title: string, text: string}> $out */
        $out = [];
        foreach ($this->policies->forVariants($ids) as $policy) {
            $words = $policy->words($locale);
            if ($words !== null) {
                $out[$policy->id] = $words;   // the same policy for several products is shown once
            }
        }

        return array_values($out);
    }
}
