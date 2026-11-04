<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use App\Modules\Catalog\Contracts\VariantDirectory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** What the staff setup screens show: services, people and rooms with their weekly hours, holidays and cancellation policies. It only reads. */
final class SetupReader
{
    public function __construct(private readonly VariantDirectory $variants) {}

    /** @return list<array<string, mixed>> */
    public function services(string $locale): array
    {
        $rows = DB::table('service_definitions as s')->leftJoin('service_packages as p', 'p.service_id', '=', 's.id')->orderBy('s.id')->get(['s.*', 'p.variant_id', 'p.duration_min', 'p.buffer_before_min', 'p.buffer_after_min', 'p.is_active as package_active']);
        $names = $this->variants->findMany(array_values(array_unique(array_filter($rows->pluck('variant_id')->map(fn ($v): int => (int) $v)->all()))));
        $policies = $this->policyTitles($locale);

        $out = [];
        foreach ($rows as $r) {
            $v = $names[(int) $r->variant_id] ?? null;
            $out[] = [
                'sku' => $v->sku ?? '', 'name' => $v->productName ?? '', 'location' => (string) $r->location_type, 'active' => (bool) $r->is_active, 'duration' => $r->duration_min === null ? null : (int) $r->duration_min,
                'policy' => $r->cancellation_policy_id === null ? null : ($policies[(int) $r->cancellation_policy_id] ?? null),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function service(string $sku, string $locale): ?array
    {
        $variant = $this->variants->findBySku($sku);
        if ($variant === null || $variant->productType !== 'service') {
            return null;
        }
        $service = DB::table('service_definitions')->where('product_id', $variant->productId)->first();
        $package = $service === null ? null : DB::table('service_packages')->where('service_id', $service->id)->where('variant_id', $variant->id)->first();

        return [
            'sku' => $variant->sku, 'name' => $variant->productName, 'service' => $service, 'package' => $package,
            'policy_public_id' => $service?->cancellation_policy_id === null ? '' : (string) DB::table('cancellation_policies')->where('id', $service->cancellation_policy_id)->value('public_id'),
            'policies' => $this->policyOptions($locale),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function resources(): array
    {
        $links = [];
        foreach (DB::table('service_resource_links as l')->join('service_definitions as s', 's.id', '=', 'l.service_id')->join('service_packages as p', 'p.service_id', '=', 's.id')->get(['l.resource_id', 'p.variant_id']) as $l) {
            $links[(int) $l->resource_id][] = (int) $l->variant_id;
        }
        $names = $this->variants->findMany(array_values(array_unique(array_merge([], ...array_values($links)))));

        $out = [];
        foreach (DB::table('service_resources')->orderBy('name')->get() as $r) {
            $out[] = ['public_id' => (string) $r->public_id, 'name' => (string) $r->name, 'type' => (string) $r->type, 'active' => (bool) $r->is_active,
                'services' => array_map(fn (int $id): string => $names[$id]->sku ?? '', $links[(int) $r->id] ?? [])];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function resource(string $publicId): ?array
    {
        $r = DB::table('service_resources')->where('public_id', $publicId)->first();   // unique public_id
        if ($r === null) {
            return null;
        }
        $linked = DB::table('service_resource_links as l')->join('service_packages as p', 'p.service_id', '=', 'l.service_id')->where('l.resource_id', $r->id)->pluck('p.variant_id')->map(fn ($v): int => (int) $v)->all();
        $linkedSkus = array_map(fn ($v) => $v->sku, array_values($this->variants->findMany(array_values($linked))));

        return [
            'row' => $r, 'linked' => $linkedSkus, 'services' => $this->bookableSkus(),
            'rules' => DB::table('availability_rules')->where('resource_id', $r->id)->orderBy('weekday')->orderBy('start_time')->get(),   // index (resource_id, weekday)
            'exceptions' => DB::table('availability_exceptions')->where('resource_id', $r->id)->where('on_date', '>=', now()->subDay()->format('Y-m-d'))->orderBy('on_date')->limit(100)->get(),   // unique (resource_id, on_date)
        ];
    }

    /** @return list<array{sku: string, name: string}> every service that can be booked, one per package */
    public function bookableSkus(): array
    {
        $ids = array_values(DB::table('service_packages')->orderBy('id')->pluck('variant_id')->map(fn ($v): int => (int) $v)->all());

        return array_values(array_map(fn ($v): array => ['sku' => $v->sku, 'name' => $v->productName], $this->variants->findMany($ids)));
    }

    /** @return Collection<int, \stdClass> */
    public function holidays()
    {
        return DB::table('holidays')->where('on_date', '>=', now()->subDay()->format('Y-m-d'))->orderBy('on_date')->limit(200)->get();   // unique on_date
    }

    /** @return list<array<string, mixed>> */
    public function policies(string $locale): array
    {
        $titles = $this->policyTitles($locale);
        $services = DB::table('service_definitions')->whereNotNull('cancellation_policy_id')->selectRaw('cancellation_policy_id, COUNT(*) as n')->groupBy('cancellation_policy_id')->pluck('n', 'cancellation_policy_id');
        $out = [];
        foreach (DB::table('cancellation_policies')->orderByDesc('is_default')->orderBy('id')->get() as $p) {
            $out[] = ['public_id' => (string) $p->public_id, 'title' => $titles[(int) $p->id] ?? (string) $p->code, 'default' => (bool) $p->is_default, 'late' => (int) $p->late_fee_bp, 'no_show' => (int) $p->no_show_fee_bp,
                'used_by' => (int) ($services[(int) $p->id] ?? 0)];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function policy(string $publicId): ?array
    {
        $p = DB::table('cancellation_policies')->where('public_id', $publicId)->first();   // unique public_id
        if ($p === null) {
            return null;
        }

        return [
            'row' => $p, 'windows' => DB::table('cancellation_policy_windows')->where('policy_id', $p->id)->orderByDesc('hours_before')->get(),   // unique (policy_id, hours_before)
            'words' => DB::table('cancellation_policy_translations')->where('policy_id', $p->id)->get()->keyBy('locale'),   // unique (policy_id, locale)
        ];
    }

    /** @return list<string> */
    public function locales(): array
    {
        return array_values(array_map('strval', DB::table('languages')->where('is_active', true)->orderByDesc('is_default')->pluck('code')->all()));
    }

    /** @return list<array{public_id: string, title: string, default: bool}> */
    private function policyOptions(string $locale): array
    {
        $titles = $this->policyTitles($locale);

        return array_values(DB::table('cancellation_policies')->orderByDesc('is_default')->orderBy('id')->get(['id', 'public_id', 'is_default'])->map(fn ($p): array => ['public_id' => (string) $p->public_id, 'title' => $titles[(int) $p->id] ?? '', 'default' => (bool) $p->is_default])->all());
    }

    /** @return array<int, string> policy id => title in this language, else English */
    private function policyTitles(string $locale): array
    {
        $out = [];
        $fallback = (string) config('app.fallback_locale');
        foreach (DB::table('cancellation_policy_translations')->whereIn('locale', array_unique([$locale, $fallback]))->get(['policy_id', 'locale', 'title']) as $t) {
            if ($t->locale === $locale || ! isset($out[(int) $t->policy_id])) {
                $out[(int) $t->policy_id] = (string) $t->title;
            }
        }

        return $out;
    }
}
