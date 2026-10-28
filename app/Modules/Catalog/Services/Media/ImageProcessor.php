<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Media;

use App\Modules\Catalog\Exceptions\MediaRejected;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Turns an untrusted upload into safe, web-ready renditions. Nothing from the original file survives: it is decoded to
 * pixels and re-encoded, so EXIF/GPS data, embedded scripts and polyglot payloads are discarded by construction.
 */
final class ImageProcessor
{
    private ImageManager $manager;

    public function __construct()
    {
        /** @var ImageManager $manager */
        $manager = ImageManager::usingDriver(Driver::class);
        $this->manager = $manager;
    }

    public static function avifSupported(): bool
    {
        return (bool) (gd_info()['AVIF Support'] ?? false);
    }

    /**
     * @return array{mime: string, width: int, height: int}
     *
     * @throws MediaRejected
     */
    public function inspect(string $bytes): array
    {
        $cfg = (array) config('catalog.media');

        if (strlen($bytes) > (int) $cfg['max_upload_bytes']) {
            throw MediaRejected::tooLarge((int) $cfg['max_upload_bytes']);
        }

        // type by CONTENT (magic bytes), never by file name or the client-supplied MIME
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, (array) $cfg['allowed_mime'], true)) {
            throw MediaRejected::notAnImage();
        }

        // read the header only: reject giant canvases before any pixel memory is allocated
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw MediaRejected::corrupt();
        }

        [$w, $h] = [(int) $size[0], (int) $size[1]];

        if (min($w, $h) < (int) $cfg['min_dimension']) {
            throw MediaRejected::dimensions('the shorter side must be at least '.$cfg['min_dimension'].' px');
        }

        if (max($w, $h) > (int) $cfg['max_dimension'] || $w * $h > (int) $cfg['max_pixels']) {
            throw MediaRejected::dimensions('the image is too large');
        }

        return ['mime' => $mime, 'width' => $w, 'height' => $h];
    }

    /**
     * @return array{width: int, height: int, master: string, renditions: array<string, array<int, string>>} binary data per format and width
     *
     * @throws MediaRejected
     */
    public function process(string $bytes): array
    {
        $this->inspect($bytes);
        $cfg = (array) config('catalog.media');

        try {
            $master = $this->manager->decodeBinary($bytes)->orient()->scaleDown(width: (int) $cfg['master_width']);
            $masterBytes = $master->encodeUsingFormat(Format::WEBP, quality: (int) $cfg['webp_quality'])->toString();
            [$width, $height] = [$master->width(), $master->height()];

            $renditions = ['webp' => []];
            $formats = ['webp' => [Format::WEBP, (int) $cfg['webp_quality']]] + (self::avifSupported() ? ['avif' => [Format::AVIF, (int) $cfg['avif_quality']]] : []);
            $widths = array_values(array_unique(array_filter([...(array) $cfg['widths'], $width], fn (int $w) => $w <= $width)));
            sort($widths);

            foreach ($formats as $name => [$format, $quality]) {
                foreach ($widths as $target) {
                    $resized = $this->manager->decodeBinary($masterBytes)->scaleDown(width: $target);
                    $renditions[$name][$target] = $resized->encodeUsingFormat($format, quality: $quality)->toString();
                }
            }
        } catch (MediaRejected $e) {
            throw $e;
        } catch (Throwable) {
            throw MediaRejected::corrupt();
        }

        return ['width' => $width, 'height' => $height, 'master' => $masterBytes, 'renditions' => $renditions];
    }
}
