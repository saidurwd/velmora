<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Media;

use App\Modules\Catalog\Models\ProductMedia;
use Illuminate\Support\Facades\Storage;

/** Builds the URLs and `srcset` strings templates and the API need. Public bucket only: never used for private files. */
final class MediaUrls
{
    /** @return array<string, string> format => srcset (e.g. "https://…/w320.webp 320w, …") */
    public function srcsets(ProductMedia $media): array
    {
        $out = [];

        foreach ($media->renditions as $format => $bySize) {
            $parts = [];
            foreach ($bySize as $width => $path) {
                $parts[(int) $width] = Storage::disk($media->disk)->url($path)." {$width}w";
            }
            ksort($parts);
            $out[$format] = implode(', ', $parts);
        }

        return $out;
    }

    /** The largest WebP at or below the requested width (or the smallest available). */
    public function url(ProductMedia $media, int $width): string
    {
        $sizes = array_map('intval', array_keys($media->renditions['webp'] ?? []));
        sort($sizes);
        $chosen = collect($sizes)->filter(fn (int $s) => $s <= $width)->last() ?? ($sizes[0] ?? 0);

        return Storage::disk($media->disk)->url((string) ($media->renditions['webp'][$chosen] ?? ''));
    }
}
