<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Actions;

use App\Modules\DigitalProducts\Services\KeyPoolReader;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/**
 * Every fifteen minutes: first fill any right that is still owed keys the pool can now cover (a safety net if an import or a grant was interrupted), then
 * write an ERROR log line for each product that has customers waiting for keys and not enough keys left (once an hour per product). Monitoring alerts on it.
 */
final class CheckKeyStock
{
    public function __construct(private readonly AllocateLicenseKeys $allocate, private readonly KeyPoolReader $pools, private readonly Cache $cache) {}

    /** @return list<int> the variants logged now */
    public function handle(): array
    {
        $this->allocate->fillWaiting();

        $logged = [];
        foreach ($this->pools->shortVariantIds() as $variantId) {
            if ($this->cache->add('digital.alert.keys_short.'.$variantId, true, now()->addHour())) {
                $counts = $this->pools->forVariant($variantId);
                Log::error('digital.alert', ['signal' => 'keys_short', 'variant_id' => $variantId, 'waiting' => $counts['waiting'], 'available' => $counts['available']]);
                $logged[] = $variantId;
            }
        }

        return $logged;
    }
}
