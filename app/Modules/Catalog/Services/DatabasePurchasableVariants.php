<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Catalog\DTOs\PurchasableVariant;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DatabasePurchasableVariants implements PurchasableVariants
{
    public function __construct(private readonly Clock $clock) {}

    public function idByPublicId(string $publicId): ?int
    {
        $id = DB::table('product_variants')->where('public_id', $publicId)->whereNull('deleted_at')->value('id');

        return $id === null ? null : (int) $id;
    }

    public function details(array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }

        $default = (string) config('app.default_locale');
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        $rows = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('product_translations as tl', fn ($j) => $j->on('tl.product_id', '=', 'p.id')->where('tl.locale', $locale))
            ->leftJoin('product_translations as td', fn ($j) => $j->on('td.product_id', '=', 'p.id')->where('td.locale', $default))
            ->whereIn('v.id', $ids)->whereNull('v.deleted_at')->whereNull('p.deleted_at')
            ->get(['v.id', 'v.public_id', 'v.sku', 'v.status as v_status', 'v.track_inventory', 'v.min_qty', 'v.max_qty', 'v.qty_step', 'v.weight_g', 'p.id as product_id', 'p.product_type', 'p.tax_class_code', 'p.status as p_status', 'p.publish_at',
                DB::raw('COALESCE(tl.name, td.name) as name'), DB::raw('COALESCE(tl.slug, td.slug) as slug')]);

        $options = $this->optionLabels(array_values(array_map(fn ($r) => (int) $r->id, $rows->all())), $locale, $default);
        $images = $this->primaryImages(array_values(array_unique(array_map(fn ($r) => (int) $r->product_id, $rows->all()))));

        $out = [];
        foreach ($rows as $r) {
            $live = $r->p_status === 'published' && ($r->publish_at === null || (string) $r->publish_at <= $now) && $r->v_status === 'active';
            $out[(int) $r->id] = new PurchasableVariant(
                (int) $r->id, (string) $r->public_id, (string) $r->sku, (string) ($r->name ?? ''), $options[(int) $r->id] ?? null, (string) ($r->slug ?? ''),
                $images[(int) $r->product_id] ?? null, (string) $r->product_type, (string) $r->tax_class_code, $live, (bool) $r->track_inventory,
                max(1, (int) $r->min_qty), $r->max_qty === null ? null : (int) $r->max_qty, max(1, (int) $r->qty_step), (int) ($r->weight_g ?? 0),
            );
        }

        return $out;
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, string> "Size: M · Colour: Navy"
     */
    private function optionLabels(array $variantIds, string $locale, string $default): array
    {
        $rows = DB::table('variant_option_values as vo')
            ->join('attribute_values as av', 'av.id', '=', 'vo.attribute_value_id')
            ->join('attributes as a', 'a.id', '=', 'av.attribute_id')
            ->leftJoin('attribute_translations as atl', fn ($j) => $j->on('atl.attribute_id', '=', 'a.id')->where('atl.locale', $locale))
            ->leftJoin('attribute_translations as atd', fn ($j) => $j->on('atd.attribute_id', '=', 'a.id')->where('atd.locale', $default))
            ->leftJoin('attribute_value_translations as vtl', fn ($j) => $j->on('vtl.attribute_value_id', '=', 'av.id')->where('vtl.locale', $locale))
            ->leftJoin('attribute_value_translations as vtd', fn ($j) => $j->on('vtd.attribute_value_id', '=', 'av.id')->where('vtd.locale', $default))
            ->whereIn('vo.variant_id', $variantIds)->orderBy('a.code')
            ->get(['vo.variant_id', DB::raw('COALESCE(atl.name, atd.name, a.code) as an'), DB::raw('COALESCE(vtl.label, vtd.label, av.code) as vn')]);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->variant_id][] = $r->an.': '.$r->vn;
        }

        return array_map(fn ($parts) => implode(' · ', $parts), $out);
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, string> product id => URL of a small WebP of its main image
     */
    private function primaryImages(array $productIds): array
    {
        $out = [];
        foreach (DB::table('product_media')->whereIn('product_id', $productIds)->whereNull('deleted_at')->orderByDesc('is_primary')->orderBy('sort_order')->get(['product_id', 'disk', 'renditions']) as $m) {
            if (isset($out[(int) $m->product_id])) {
                continue;
            }
            $webp = (array) (json_decode((string) $m->renditions, true)['webp'] ?? []);
            ksort($webp, SORT_NUMERIC);
            $path = $webp[320] ?? $webp[640] ?? reset($webp);
            if ($path) {
                $out[(int) $m->product_id] = Storage::disk((string) $m->disk)->url((string) $path);
            }
        }

        return $out;
    }
}
