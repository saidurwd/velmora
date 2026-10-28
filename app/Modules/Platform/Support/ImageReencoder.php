<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use DomainException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Turns an untrusted upload into clean responsive WebP files: the type is sniffed from the bytes (never the file name),
 * the pixel count is checked before decoding, and re-encoding drops every metadata block and appended payload.
 */
final class ImageReencoder
{
    public const MAX_BYTES = 8_388_608;

    public const MAX_PIXELS = 30_000_000;

    /**
     * @param  non-empty-list<int>  $widths  target widths, ascending; never upscaled
     * @return array{width: int, height: int, files: array<int, string>} widest rendition's size and bytes per width
     *
     * @throws DomainException message is a short reason code: too_large, not_image, too_small
     */
    public function webp(string $bytes, array $widths, int $minWidth, int $quality = 80): array
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new DomainException('too_large');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new DomainException('not_image');
        }
        if ($info[0] < $minWidth) {
            throw new DomainException('too_small');
        }

        try {
            $manager = ImageManager::usingDriver(Driver::class);
            $master = $manager->decodeBinary($bytes)->orient()->scaleDown(width: max($widths))->encodeUsingFormat(Format::WEBP, quality: $quality)->toString();
            $masterSize = getimagesizefromstring($master);
            $files = [];

            foreach ($widths as $width) {
                if ($width <= ($masterSize[0] ?? 0)) {
                    $files[$width] = $manager->decodeBinary($master)->scaleDown(width: $width)->encodeUsingFormat(Format::WEBP, quality: $quality)->toString();
                }
            }
            if ($files === []) {
                $files[(int) ($masterSize[0] ?? 0)] = $master; // smaller than the smallest target: keep the single master
            }

            return ['width' => (int) ($masterSize[0] ?? 0), 'height' => (int) ($masterSize[1] ?? 0), 'files' => $files];
        } catch (Throwable) {
            throw new DomainException('not_image');
        }
    }
}
