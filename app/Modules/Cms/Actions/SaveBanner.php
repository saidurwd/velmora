<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Cms\Models\Banner;
use App\Modules\Cms\Models\BannerTranslation;
use App\Modules\Cms\Support\SafeLink;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\ImageReencoder;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** A banner: placement, link, schedule, and per-language text + image (the image may carry Bangla lettering). */
final class SaveBanner
{
    public const PLACEMENTS = ['home_hero', 'home_strip'];

    public function __construct(private readonly ImageReencoder $images, private readonly AuditLogger $audit) {}

    /**
     * @param  array{placement: string, link_url: string|null, sort_order: int, is_active: bool, starts_local: string|null, ends_local: string|null}  $data  times are Asia/Dhaka wall-clock
     * @param  array<string, array{title?: string|null, subtitle?: string|null, cta_label?: string|null, alt?: string|null, image?: string|null}>  $translations  image = raw upload bytes
     */
    public function handle(Actor $actor, ?Banner $banner, array $data, array $translations): Banner
    {
        $default = (string) config('app.default_locale');
        $zone = (string) config('app.display_timezone');
        $disk = Storage::disk((string) config('catalog.media.disk', 'media'));

        in_array($data['placement'], self::PLACEMENTS, true) || throw new CmsException('placement_invalid');
        ($data['link_url'] ?? '') === '' || SafeLink::isSafe((string) $data['link_url']) || throw CmsException::unsafeLink();

        $starts = ($data['starts_local'] ?? '') === '' ? null : CarbonImmutable::parse((string) $data['starts_local'], $zone)->utc();
        $ends = ($data['ends_local'] ?? '') === '' ? null : CarbonImmutable::parse((string) $data['ends_local'], $zone)->utc();
        ($starts === null || $ends === null || $ends->greaterThan($starts)) || throw CmsException::badWindow();

        $existing = $banner === null ? collect() : $banner->translations()->get()->keyBy('locale');
        ($existing->get($default)?->renditions !== null || ! empty($translations[$default]['image'])) || throw CmsException::imageRequired($default);

        $written = [];

        try {
            // images first (outside the DB transaction); remembered so a later failure can remove them
            $prepared = [];
            foreach ($translations as $locale => $t) {
                $bytes = $t['image'] ?? null;
                if ($bytes === null || $bytes === '') {
                    continue;
                }
                try {
                    $out = $this->images->webp($bytes, [800, 1600], 800);
                } catch (DomainException $e) {
                    throw CmsException::image($e->getMessage());
                }
                $base = 'banners/'.Str::lower((string) Str::ulid());
                $renditions = [];
                foreach ($out['files'] as $width => $file) {
                    $path = "{$base}-{$width}.webp";
                    $disk->put($path, $file, 'public');
                    $written[] = $path;
                    $renditions[(string) $width] = $path;
                }
                $prepared[$locale] = ['renditions' => $renditions, 'width' => $out['width'], 'height' => $out['height']];
            }

            $old = [];
            $banner = Transactions::run(function () use ($actor, $banner, $data, $translations, $starts, $ends, $prepared, $existing, &$old): Banner {
                $isNew = $banner === null;
                $banner ??= new Banner(['created_by' => $actor->id]);
                $banner->forceFill([
                    'placement' => $data['placement'], 'link_url' => ($data['link_url'] ?? '') === '' ? null : $data['link_url'],
                    'sort_order' => max(0, min(65535, $data['sort_order'])), 'is_active' => $data['is_active'], 'starts_at' => $starts, 'ends_at' => $ends,
                ])->save();

                foreach ($translations as $locale => $t) {
                    $row = $existing->get($locale);
                    $img = $prepared[$locale] ?? null;
                    $alt = BanglaText::input((string) ($t['alt'] ?? ''));
                    ($alt !== '' || ($img === null && $row?->renditions === null)) || throw CmsException::altRequired((string) $locale);
                    $img === null || $row?->renditions === null || $old = [...$old, ...array_values($row->renditions)];

                    BannerTranslation::query()->updateOrCreate(['banner_id' => $banner->id, 'locale' => $locale], array_filter([
                        'title' => BanglaText::input((string) ($t['title'] ?? '')) ?: null,
                        'subtitle' => BanglaText::input((string) ($t['subtitle'] ?? '')) ?: null,
                        'cta_label' => BanglaText::input((string) ($t['cta_label'] ?? '')) ?: null,
                        'alt' => $alt ?: null,
                    ], fn ($v) => $v !== null) + ['title' => null, 'subtitle' => null, 'cta_label' => null, 'alt' => null]
                        + ($img === null ? [] : ['renditions' => $img['renditions'], 'width' => $img['width'], 'height' => $img['height']]));
                }

                $this->audit->record($isNew ? 'cms.banner_created' : 'cms.banner_updated', $banner, [], ['placement' => $data['placement'], 'active' => $data['is_active']], $actor);
                $banner->unsetRelation('translations');

                return $banner;
            });

            foreach ($old as $path) {
                $disk->delete($path); // replaced images, removed only after the new rows are committed
            }

            return $banner;
        } catch (Throwable $e) {
            foreach ($written as $path) {
                $disk->delete($path);
            }
            throw $e;
        }
    }
}
