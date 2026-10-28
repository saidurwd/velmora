<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Contracts\BannerSource;
use App\Modules\Cms\DTOs\BannerView;
use App\Modules\Cms\Models\Banner;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\Storage;

final class DatabaseBannerSource implements BannerSource
{
    public function __construct(private readonly Clock $clock) {}

    public function active(string $placement, string $locale): array
    {
        $now = $this->clock->now();
        $default = (string) config('app.default_locale');
        $disk = Storage::disk((string) config('catalog.media.disk', 'media'));

        $banners = Banner::query()->with('translations')
            ->where('placement', $placement)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderBy('sort_order')->orderBy('id')->get();

        $out = [];
        foreach ($banners as $banner) {
            $t = $banner->translations->firstWhere('locale', $locale);
            $image = $t?->renditions !== null ? $t : $banner->translations->firstWhere('locale', $default); // image of the language, else the default one
            if ($image === null || $image->renditions === null) {
                continue;
            }

            $renditions = $image->renditions;
            ksort($renditions, SORT_NUMERIC);
            $srcset = implode(', ', array_map(fn ($w, $p) => $disk->url($p)." {$w}w", array_keys($renditions), $renditions));
            $text = $t ?? $image;

            $out[] = new BannerView(
                $banner->public_id, $text->title, $text->subtitle, $text->cta_label, $banner->link_url,
                (string) ($text->alt ?? $image->alt ?? ''), $disk->url((string) end($renditions)), $srcset, (int) $image->width, (int) $image->height,
            );
        }

        return $out;
    }
}
