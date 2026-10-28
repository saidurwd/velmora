<?php

declare(strict_types=1);

namespace App\Modules\Cms\DTOs;

/** A banner ready to render for one language. `src` is the widest rendition; `srcset` lists all. */
final readonly class BannerView
{
    public function __construct(
        public string $id,
        public ?string $title,
        public ?string $subtitle,
        public ?string $ctaLabel,
        public ?string $linkUrl,
        public string $alt,
        public string $src,
        public string $srcset,
        public int $width,
        public int $height,
    ) {}
}
