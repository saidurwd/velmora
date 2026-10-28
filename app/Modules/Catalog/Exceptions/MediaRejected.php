<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use DomainException;

final class MediaRejected extends DomainException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function tooLarge(int $max): self
    {
        return new self('The file is larger than '.intdiv($max, 1024 * 1024).' MB.', 'too_large');
    }

    public static function notAnImage(): self
    {
        return new self('Only JPEG, PNG or WebP images are accepted.', 'not_an_image');
    }

    public static function dimensions(string $why): self
    {
        return new self("The image dimensions are not acceptable: {$why}.", 'dimensions');
    }

    public static function malware(): self
    {
        return new self('The file did not pass the security scan.', 'malware');
    }

    public static function altRequired(string $locale): self
    {
        return new self("Alt text in [{$locale}] is required (or mark the image as decorative).", 'alt_required');
    }

    public static function corrupt(): self
    {
        return new self('The image could not be read.', 'corrupt');
    }
}
