<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Services\SavableVariants;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/**
 * "Tell me when it is back." Only for an item that is out of stock now (otherwise there is nothing to wait for). One alert per customer and variant: asking again while
 * waiting changes nothing, and asking again after being told makes it wait again. The customer's language is kept for the message.
 */
final class SubscribeStockAlert
{
    public function __construct(private readonly SavableVariants $variants, private readonly Availability $availability, private readonly Clock $clock) {}

    /** @throws EngagementException not_found | service | in_stock | too_many_alerts */
    public function handle(int $userId, string $variantPublicId, string $locale): void
    {
        $variant = $this->variants->resolve($variantPublicId, $locale);
        if (! $variant->trackInventory || $this->availability->forVariant($variant->id) > 0) {
            throw new EngagementException('in_stock');
        }

        $now = $this->clock->now();
        $locale = in_array($locale, ['en', 'bn'], true) ? $locale : 'en';
        $existing = DB::table('stock_alerts')->where('user_id', $userId)->where('variant_id', $variant->id)->first();   // unique (user_id, variant_id)
        if ($existing !== null && $existing->status === 'pending') {
            return;
        }
        if (DB::table('stock_alerts')->where('user_id', $userId)->where('status', 'pending')->count() >= ($max = (int) config('engagement.alerts_max', 100))) {
            throw new EngagementException('too_many_alerts', ['max' => $max]);
        }

        if ($existing === null) {
            DB::table('stock_alerts')->insertOrIgnore(['user_id' => $userId, 'variant_id' => $variant->id, 'status' => 'pending', 'locale' => $locale, 'created_at' => $now, 'updated_at' => $now]);

            return;
        }
        DB::table('stock_alerts')->where('id', $existing->id)->where('status', 'notified')->update(['status' => 'pending', 'notified_at' => null, 'locale' => $locale, 'created_at' => $now, 'updated_at' => $now]);
    }
}
