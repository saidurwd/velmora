<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Services;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Orders\Contracts\OrderDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** What a customer sees in their library: one row per download they own, in a fixed number of queries however many there are. No storage key or address is ever in a row. */
final class LibraryReader
{
    public function __construct(private readonly VariantDirectory $variants, private readonly OrderDirectory $orders) {}

    /**
     * @param  list<int>  $orderIds
     * @return list<array{id: string, order: string, product: string, sku: string, file: string|null, version: int|null, left: int|null, unlimited: bool, expires_at: CarbonImmutable|null, state: string, keys_needed: int, keys_ready: int}>
     */
    public function forOrders(array $orderIds, string $locale): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rights = DB::table('entitlements')->whereIn('order_id', $orderIds)->orderByDesc('id')->limit(200)->get();   // index order_id
        $variantIds = array_values(array_unique(array_map('intval', $rights->pluck('variant_id')->all())));
        $names = $this->variants->findMany($variantIds);
        $refs = $this->orders->references(array_values(array_unique(array_map('intval', $rights->pluck('order_id')->all()))));
        $current = [];
        foreach (DB::table('digital_assets as a')->join('digital_asset_versions as v', 'v.asset_id', '=', 'a.id')->whereIn('a.variant_id', $variantIds)->orderBy('v.version')->get(['a.variant_id', 'v.version', 'v.original_filename']) as $v) {
            $current[(int) $v->variant_id] = $v;   // ascending, so the newest version is the last one kept
        }

        $ready = DB::table('license_keys')->whereIn('entitlement_id', $rights->pluck('id')->map(fn ($v): int => (int) $v)->all())->where('status', 'allocated')->groupBy('entitlement_id')->selectRaw('entitlement_id, COUNT(*) as n')->pluck('n', 'entitlement_id');   // index entitlement_id

        $now = CarbonImmutable::now('UTC');
        $rows = [];
        foreach ($rights as $r) {
            $expires = $r->expires_at === null ? null : CarbonImmutable::parse((string) $r->expires_at, 'UTC');
            $left = $r->download_limit === null ? null : max(0, (int) $r->download_limit - (int) $r->downloads_used);
            $file = $current[(int) $r->variant_id] ?? null;
            $state = match (true) {
                $r->status !== 'active' => 'revoked',
                $file === null && (int) $r->keys_needed > 0 => 'available',   // a product that is only keys has no file to run out of
                $expires !== null && $expires->lessThanOrEqualTo($now) => 'expired',
                $left === 0 => 'limit_reached',
                $file === null => 'not_ready',
                default => 'available',
            };
            $rows[] = [
                'id' => (string) $r->public_id, 'order' => $refs[(int) $r->order_id]['number'] ?? '', 'product' => $names[(int) $r->variant_id]->productName ?? '', 'sku' => $names[(int) $r->variant_id]->sku ?? '',
                'file' => $file === null ? null : (string) $file->original_filename, 'version' => $file === null ? null : (int) $file->version, 'left' => $left, 'unlimited' => $r->download_limit === null, 'expires_at' => $expires, 'state' => $state, 'keys_needed' => (int) $r->keys_needed, 'keys_ready' => (int) ($ready[(int) $r->id] ?? 0),
            ];
        }

        return $rows;
    }
}
