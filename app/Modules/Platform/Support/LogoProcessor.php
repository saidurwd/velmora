<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use DomainException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/** Logos are re-encoded as PNG (no SVG: scripts cannot ride along; no metadata survives) and capped in size. */
final class LogoProcessor
{
    public const MAX_BYTES = 1_048_576;

    public const MAX_WIDTH = 640;

    public const MAX_HEIGHT = 200;

    /** @throws DomainException */
    public function process(string $bytes): string
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new DomainException('logo_too_large');
        }

        $type = @getimagesizefromstring($bytes);
        if ($type === false || ! in_array($type[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) || $type[0] * $type[1] > 16_000_000) {
            throw new DomainException('logo_not_image');
        }

        try {
            return ImageManager::usingDriver(Driver::class)->decodeBinary($bytes)->orient()
                ->scaleDown(width: self::MAX_WIDTH, height: self::MAX_HEIGHT)
                ->encodeUsingFormat(Format::PNG)->toString();
        } catch (Throwable) {
            throw new DomainException('logo_not_image');
        }
    }
}
