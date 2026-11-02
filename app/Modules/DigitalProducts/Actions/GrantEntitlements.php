<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Actions;

use App\Modules\DigitalProducts\Models\DigitalAsset;
use App\Modules\Orders\Contracts\DigitalOrderLines;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A paid order's digital lines become rights to download. One right per order line (a database UNIQUE), so a repeated or parallel call can never make a
 * second. The number of downloads and the time allowed come from the product's own rule, else the shop's default. The file need not exist yet: if staff
 * have not uploaded it, the customer simply cannot download until they do. Nothing here decides who is the customer; that stays with the order.
 */
final class GrantEntitlements
{
    public function __construct(private readonly DigitalOrderLines $lines, private readonly Settings $settings, private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @return int how many rights were made now (0 when they already existed, or the order has nothing digital or is not paid) */
    public function handle(int $orderId): int
    {
        $lines = $this->lines->paidLinesFor($orderId);
        if ($lines === []) {
            return 0;
        }

        $now = $this->clock->now();
        $rules = [];
        foreach (DigitalAsset::query()->whereIn('variant_id', array_map(fn ($l) => $l->variantId, $lines))->get() as $asset) {
            $rules[$asset->variant_id] = $asset;
        }
        $defaultLimit = $this->settings->int('digital.download_limit', (int) config('digital.download_limit'));
        $made = 0;

        foreach ($lines as $line) {
            $rule = $rules[$line->variantId] ?? null;
            $limit = $defaultLimit;
            $days = null;
            if ($rule instanceof DigitalAsset) {
                $limit = $rule->download_limit ?? $defaultLimit;
                $days = $rule->access_days;
            }
            $created = DB::table('entitlements')->insertOrIgnore([
                'public_id' => strtolower((string) Str::ulid()), 'order_id' => $orderId, 'order_item_id' => $line->orderItemId, 'variant_id' => $line->variantId, 'status' => 'active',
                'download_limit' => $limit < 1 ? null : $limit, 'downloads_used' => 0, 'expires_at' => $days === null ? null : $now->addDays($days),
                'granted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($created === 1) {
                $made++;
                $this->audit->record('digital.entitlement_granted', 'entitlement', [], ['order_id' => $orderId, 'order_item_id' => $line->orderItemId, 'limit' => $limit], Actor::system('digital'), $line->orderItemId);
            }
        }

        return $made;
    }
}
