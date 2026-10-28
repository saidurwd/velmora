<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Services\Storefront\ListingProjection;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Contracts\PriceCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Every minute: refresh listing rows for products whose price (incl. sales starting/ending) or stock changed since the
 * last run. This is the documented staleness window for listing prices and availability (≈ 1 minute); product pages and
 * the cart always read live values.
 */
class ReconcileListing implements ShouldQueue
{
    use Queueable;

    private const KEY = 'catalog.listing_reconciled_at';

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(ListingProjection $projection, PriceCatalog $prices, Availability $availability, Settings $settings, Clock $clock): void
    {
        $until = $clock->now();
        $stored = $settings->string(self::KEY);
        $since = $stored === '' ? $until->subMinutes(5) : CarbonImmutable::parse($stored, 'UTC')->subSeconds(5); // small overlap: never miss a change on the boundary

        $variantIds = array_values(array_unique([...$prices->changedVariantIds($since, $until), ...$availability->changedVariantIds($since, $until)]));

        if ($variantIds !== []) {
            $productIds = array_values(array_map('intval', DB::table('product_variants')->whereIn('id', $variantIds)->distinct()->pluck('product_id')->all()));
            $projection->refreshMany($productIds);
        }

        $settings->set(self::KEY, $until->utc()->format('Y-m-d H:i:s.u'));
    }
}
